<?php

class i18nTranslateTask extends sfBaseTask
{
  protected function configure()
  {
    $this->namespace        = 'i18n';
    $this->name             = 'translate';
    $this->briefDescription = 'Translate word and sentences from xliff files using Google AJAX Language API';
    $this->detailedDescription = <<<EOF
The [i18nTranslate|INFO] attempt to translate your sentences using Google AJAX Language API.

Call it with:

  [./symfony i18n:translate frontend en it|INFO]

EOF;
    //add arguments here, like the following:
    $this->addArgument('application', sfCommandArgument::REQUIRED, 'The application name');
    $this->addArgument('source', sfCommandArgument::REQUIRED, 'The source language');
    $this->addArgument('destination', sfCommandArgument::REQUIRED, 'The destination language');

    // add options here, like the following:
    $this->addOption('force-destination', null, sfCommandOption::PARAMETER_REQUIRED, 'Delete source file if exists', 'false');
  }

  protected function execute($arguments = array(), $options = array())
  {	
	$delete_source = false;
	$source = $arguments['source'];
	$destination = $arguments['destination'];
	$base_dir = sfConfig::get('sf_root_dir').DIRECTORY_SEPARATOR.'apps'.DIRECTORY_SEPARATOR.$arguments['application'].DIRECTORY_SEPARATOR.'i18n'.DIRECTORY_SEPARATOR;
	
	$dest_files = sfFinder::type('file')->name('*.xml')->relative()->in( $base_dir.$destination );
	if ( count( $dest_files ) > 0  && $options['force-destination'] == 'false') {
		$this->logSection('i18n', 'Cannot overwrite destination dir ( use: --force-destination=true )');
		exit;
	}
	
	$files = sfFinder::type('file')->name('*.xml')->relative()->in( $base_dir.$source );
	
	if ( !(count($files) > 0) ) {
		//try to generate them throu extract task
		$sfI18nExtractTask = new sfI18nExtractTask($this->dispatcher, $this->formatter);
		$sfI18nExtractTask->run($arguments = array('application' => $arguments['application'], 'culture' => $source ), $options = array('auto-save'));
		
		$files = sfFinder::type('file')->name('*.xml')->relative()->in( $base_dir.$source );
		$delete_source = true;
	}
	
	$this->logSection('i18n', 'Starting translation');
	
	$count = 0;
	$errors = 0;
	
	foreach ($files as $file) {	
		$source_file = $base_dir.$source.DIRECTORY_SEPARATOR.$file;
		$destination_file = $base_dir.$destination.DIRECTORY_SEPARATOR.$file;
		
		if (file_exists($source_file)) {
		    $this->logSection('i18n', sprintf('Reading %s', $file ));
			
			$contents = file_get_contents($source_file);
			$xml = simplexml_load_string( $contents );
			$body = "";
			foreach ($xml->file->body->{'trans-unit'} as $item) {
				$translation = $this->translate( $item->source, $source, $destination );
				
				if ($translation) {
					$body .= "\t\t\t".'<trans-unit id="'.$item['id'].'">'."\n";
					$body .= "\t\t\t\t<source>".$item->source."</source>\n";
					$body .= "\t\t\t\t<target>".$translation."</target>\n";
					$body .= "\t\t\t</trans-unit>\n";
					$count++;
				} else {
					$errors++;
				}
			}
		
			$header = sprintf('<?xml version="1.0"?>
			<xliff version="1.0">
			  <file source-language="%s" target-language="%s" datatype="plaintext" original="messages" date="'.$xml->file['date'].'" product-name="messages">
			    <body>'."\n", $source, $destination );
			$footer = '</body>
			  </file>
			</xliff>';
		
			if (!is_dir($base_dir.$destination )) {
				mkdir( $base_dir.$destination );
				chmod( $base_dir.$destination, 0777 );
			}
		
			file_put_contents( $destination_file, $header.$body.$footer );
		    $this->logSection('i18n', sprintf('Writing %s', $file ));
		
		}
		
		if ($delete_source) {
			unlink ( $base_dir.$source.DIRECTORY_SEPARATOR.$file );
		} 
	}
	
	$this->logSection('i18n', sprintf( '%d sentences translated', $count ));
	
	if  ($errors > 0 ) 
		$this->logSection('i18n', sprintf( '%d sentences NOT translated', $errors ));			
				
	$this->logSection('i18n', 'finished');
	
	
	if ($delete_source) {
		rmdir( $base_dir.$source);
	}

  }

  protected function translate( $sentence, $source, $dest ) {
	$url = "http://ajax.googleapis.com/ajax/services/language/translate?v=1.0&q=".urlencode($sentence)."&langpair=".$source."%7C".$dest;

	// sendRequest
	// note how referer is set manually
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_REFERER, sfConfig::get('app_i18nTranslatePlugin_referer', 'http://livepetitions.com' ));
	$body = curl_exec($ch);
	curl_close($ch);

	// now, process the JSON string
	$json = json_decode($body);
	// now have some fun with the results...
	if ($json->responseStatus == 200) {
		return $json->responseData->translatedText;
	} else {
		return false;
	}
  }

}