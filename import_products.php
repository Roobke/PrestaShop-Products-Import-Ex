<?php
	#Include required files:
	include(dirname(__FILE__).'/config/settings.inc.php');
	include(dirname(__FILE__).'/config/defines.inc.php');
	include(dirname(__FILE__).'/config/config.inc.php');
	require_once(dirname(__FILE__).'/init.php');
	
	#Errors displaying:
	error_reporting(E_ALL | E_STRICT);
	ini_set('display_errors', 1);
	
	#Custom functions:
	function format_uri($string)
	{
		$string = trim(strtolower($string));
		$string = str_replace(' ', '-', $string);
		$string = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($string, Normalizer::FORM_KD));
		$string = preg_replace('/[^a-zA-Z0-9-]+/', '', $string);
		
	    return $string;
	}
	
	function file_get_contents_curl($url)
	{
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);       
		
		$data = curl_exec($ch);
		
		curl_close($ch);

		return $data;
	}
	
	#Add existring products in array:
	$results = Db::getInstance()->ExecuteS("SELECT p.id_product, p.ean13 FROM "._DB_PREFIX_."product p WHERE p.ean13 IS NOT NULL AND p.ean13 != ''");
	$existing_products = array();
	
	if (count($results)) {
		foreach ($results as $result) {
			$existing_products[$result['id_product']] = $result['ean13'];
		}
	}

	#Import or update products:
	$config = array(
        'indent'     => true,
        'input-xml'  => true,
        'output-xml' => true,
        'wrap' => false
	);
	
	$tidy = new tidy;
	$tidy->parseString(file_get_contents_curl("https://sunbaby.pl/xmlapi/1/2/utf8/dc885d9c-78be-4491-90af-3fff46dfd8ac"), $config, 'utf8');
	$tidy->cleanRepair();
	$xml = simplexml_load_string($tidy, 'SimpleXMLElement', LIBXML_NOCDATA) or die("Error: Cannot create object.");
	$i = 0;
	
	if (count($xml->product)) {
		foreach ($xml->product as $key => $product) {
			$i++;
			$product->name = trim((string) $product->name);
			$product->sku = trim((string) $product->sku);
			$format_uri = format_uri($product->name);
			$sk = Db::getInstance()->getValue("SELECT COUNT(*) FROM "._DB_PREFIX_."product p WHERE p.ean13 = '".$product->sku."'");
			
			$category_key = 1536;
			
			if ($sk > 0) {
				$product_key = array_search($format_uri, $existing_products);
				$updating_product = new Product($product_key);
				$updating_product->quantity = intval($product->qty);
				
				if (empty($updating_product->name)) $updating_product->name = array((int) Configuration::get('PS_LANG_DEFAULT') => $product->name);
				if (empty($updating_product->link_rewrite)) $updating_product->link_rewrite = array((int) Configuration::get('PS_LANG_DEFAULT') => $format_uri);
				
				StockAvailable::setQuantity((int) $product_key, 0, $product->qty, Context::getContext()->shop->id);
				
				$updating_product->update();
			} elseif ($product->qty > 0) {
				$new_product = new Product();
				$new_product->name = array((int) Configuration::get('PS_LANG_DEFAULT') => $product->name);
				$new_product->link_rewrite = array((int) Configuration::get('PS_LANG_DEFAULT') => $format_uri);
				//$new_product->id_category = $category_key;
				//$new_product->id_category_default = $category_key;
				$new_product->description_short = substr($product->desc, 0, 800);
				$new_product->redirect_type = '404';
				$new_product->price = str_replace(',', '.', $product->priceAfterDiscountNet);
				$new_product->quantity = intval($product->qty);
				$new_product->ean13 = $product->sku;
				$new_product->minimal_quantity = 1;
				$new_product->show_price = 1;
				$new_product->active = 0;
				$new_product->add();
				
				StockAvailable::setQuantity((int) $new_product->id, 0, $product->qty, Context::getContext()->shop->id);
				
				$new_product->addToCategories(array($category_key));
				
				foreach ($product->photos->photo as $img_value) {
					$shops = Shop::getShops(true, null, true);
					$image = new Image();
					$image->id_product = $new_product->id;
					$image->position = Image::getHighestPosition($new_product->id) + 1;
					
					if (Image::getImagesTotal($new_product->id) > 0) $image->cover = false;
					else $image->cover = true;
					
					if (($image->validateFields(false, true)) === true && ($image->validateFieldsLang(false, true)) === true && $image->add()) {
						$image->associateTo($shops);
						
						#######class > AdminImportController function > copyImg 'private' need to change to 'public'.
						if (!AdminImportController::copyImg($new_product->id, $image->id, (string) $img_value, 'products', true)) {
							$image->delete();
						}
					}
				}
			}
		}
	}
?>