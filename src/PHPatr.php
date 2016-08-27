<?php

namespace PHPatr
{
	use Phar;
	use Exception;
	use GuzzleHttp\Client;
	use PHPatr\Exceptions\ConfigFileNotFoundException;
	use PHPatr\Exceptions\ErrorTestException;

	class PHPatr
	{
		private $_client;
		private $_auths = array();
		private $_bases = array();
		private $_configFile = './phpatr.json';
		private $_hasError = false;
		private $_saveFile = false;

		public function init()
		{
			$args = func_get_args();
			if($args[0] == 'index.php'){
				unset($args[0]);
			}
			while($value = current($args)){
				switch($value){
					case '--config':
					case '-c':
						$this->_configFile = next($args);
						break;
					case '--output':
					case '-o':
						$this->_saveFile = next($args);
						break;
					default:
					case '--help':
					case '-h':
						$this->_help();
						break;
				}
				next($args);
			}
			if($this->_saveFile){
				$this->_resetLogFile();
			}
			return $this->_run();
		}

		private function _run()
		{
			$configFile = str_replace($_SERVER['argv'][0], '', Phar::running(false)) . $this->_configFile;
			if(!is_file($configFile)){
				throw new ConfigFileNotFoundException($configFile);
			}
			$this->_log('Start: ' . date('Y-m-d H:i:s'));
			$this->_log('Config File: ' . $this->_configFile);
			$this->_config = json_decode(file_get_contents($configFile), true);
			$this->_log('Test Config: ' . $this->_config['name']);
			$this->_configAuth();
			$this->_configBase();
			$this->_log('Run Tests!');

			if(count($this->_config['tests']) > 0){
				foreach($this->_config['tests'] as $test){

					$base = $this->_bases[$test['base']];
					$auth = $this->_auths[$test['auth']];

					$header = [];
					$query = [];

					if(array_key_exists('header', $base)){
						$header = array_merge($header, $base['header']);
					}
					if(array_key_exists('query', $base)){
						$query = array_merge($query, $base['query']);
					}

					if(array_key_exists('header', $auth)){
						$header = array_merge($header, $auth['header']);
					}
					if(array_key_exists('query', $auth)){
						$query = array_merge($query, $auth['query']);
					}

					// debug(compact('header', 'query'));

					$this->_client = new Client([
						'base_uri' => $base['url'],
						'timeout' => 10,
						'allow_redirects' => false,
					]);

					$assert = $test['assert'];

					$statusCode = $assert['code'];

					try {
						$response = $this->_client->request('GET', $test['path'], [
							'query' => $query,
							'headers' => $header
						]);	
					} catch(Exception $e){
						if($e->getCode() == $statusCode){
							$this->_success($base, $auth, $test);
							break;
						}else{
							$this->_error($base, $auth, $test);
							break;
						}
					}

					if($response->getStatusCode() != $statusCode){
						$this->_error($base, $auth, $test);
						break;
					}

					switch($assert['type']){
						case 'json':
							$body = $response->getBody();
							$json = array();
							while (!$body->eof()) {
								$json[] = $body->read(1024);
							}
							$json = implode($json);
							if(
								(substr($json, 0, 1) == '{' && substr($json, -1) == '}') ||
								(substr($json, 0, 1) == '[' && substr($json, -1) == ']')
							){
								$json = json_decode($json, true);

								if(!$this->_parseJson($assert['fields'], $json)){
									$this->_error($base, $auth, $test);
									break;
								}else{
									$this->_success($base, $auth, $test);
									break;
								}

							}else{
								$this->_error($base, $auth, $test);
								break;
							}
							break;
					}
				}
			}
			$this->_log('End: ' . date('Y-m-d H:i:s'));
			if($this->_hasError){
				throw new ErrorTestException();
			}
		}

		private function _parseJson($required, $json)
		{
			if(is_array($required) && is_array($json)){

				$findFields = array();

				foreach($required as $indexRequired => $valueRequired){

					$error = false;

					foreach($json as $indexJson => $valueJson){

						if(is_array($valueRequired) && is_array($valueJson)){
							return $this->_parseJson($valueRequired, $valueJson);
						}else{

							if(is_array($valueRequired) || is_array($valueJson)){
								$error = true;
							}else{
								if($indexJson == $indexRequired){
									if($valueRequired != gettype($valueJson)){
											$error = true;
									}else{
										$success[] = $valueJson;
									}
								}
							}
							
						}
					}

					if($error){
						return false;
					}
					
				}

				if(count($success) == count($required)){
					return true;
				}
				
			}
			return false;
		}

		private function _configAuth()
		{
			$this->_auths = array();
			foreach($this->_config['auth'] as $auth){
				$this->_auths[$auth['name']] = $auth;
			}
		}

		private function _configBase()
		{
			$this->_bases = array();
			foreach($this->_config['base'] as $base){
				$this->_bases[$base['name']] = $base;
			}
		}

		private function _log($message, $array = false)
		{
			echo "LOG: \033[33m$message\033[0m \n";
			if($array && is_array($array)){
				print_r($array);
			}
			if($this->_saveFile){
				$this->_logFile('LOG: ' . $message . "\n");
			}
		}

		private function _error($base, $auth, $test)
		{
			$this->_hasError = 1;
			echo "[\033[31mFAIL\033[0m] " . $test['name'] . " \n";
			if($this->_saveFile){
				$this->_logFile('[FAIL] ' . $test['name'] . "\n");
			}
		}

		private function _success($base, $auth, $test)
		{
			echo "[\033[32m OK \033[0m] " . $test['name'] . " \n";
			if($this->_saveFile){
				$this->_logFile('[ OK ] ' . $test['name'] . "\n");
			}
		}

		private function _logFile($message)
		{
			$fopen = fopen($this->_saveFile, 'a');
			fwrite($fopen, 'LOG: ' . $message);
			fclose($fopen);
		}

		private function _resetLogFile()
		{
			unlink($this->_saveFile);
		}

		private function _help()
		{
			echo "   \033[33mUsage:\033[0m\n";
			echo "	\033[32m php phpatr.phar -c <config file> \033[0m \n\n";
			echo "	Options:\n";
			echo "	\033[37m  -c, --config                     File of configuration in JSON to test API REST calls \033[0m \n";
			echo "	\033[37m  -h, --help                       Show this menu \033[0m \n";
			echo "	\033[37m  -o, --output                     Output file to save log \033[0m";
			die(1);
		}
	}
}