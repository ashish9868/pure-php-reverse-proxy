<?php

// Credit: https://www.sebastian-lechner.info/a-minimal-reverse-proxy-using-php-curl/

// Configuration parameters
$proxied_url = 'http://localhost:9000';

// Define getallheaders() in case that it doesn't already exist (e.g. Nginx, PHP-FPM, FastCGI)
// Taken from https://www.php.net/manual/en/function.getallheaders.php#84262
if (!function_exists('getallheaders')) { 
    function getallheaders() { 
       $headers = array (); 
       foreach ($_SERVER as $name => $value) { 
           if (substr($name, 0, 5) == 'HTTP_') { 
               $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value; 
           } 
       } 
       return $headers; 
    } 
} 
function reformat($headers) {
    foreach ($headers as $name => $value) {
        yield "$name: $value";
    }
}
$proxied_host = parse_url($proxied_url)['host'];
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
// HTTP messages consist of a request line such as 'GET https://example.com/asdf HTTP/1.1'…
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
curl_setopt($ch, CURLOPT_URL, $proxied_url . $_SERVER['REQUEST_URI']);
// … a set of header fields…
$request_headers = getallheaders(); 
$request_headers['host'] = $proxied_host;
// $request_headers['X-Forwarded-Host'] = $_SERVER['SERVER_NAME'];

// … and a message body.
$request_body = file_get_contents('php://input');
// PHP has a flaw where php://input returns empty when Content-Type is multipart/form-data!
if (!$request_body && $_SERVER['REQUEST_METHOD'] === 'POST' && count($_POST) && stripos($request_headers['Content-Type'], 'multipart/form-data') !== false) {
    $multipart = $_POST;
    foreach($_FILES as $fileFieldName => $fileInfo){
        $multipart[$fileFieldName] = curl_file_create($fileInfo['tmp_name'], $fileInfo['type'], $fileInfo['name']);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
} else {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
}


$request_headers = iterator_to_array(reformat($request_headers));
curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

// Retrieve response headers in the same request as the body
// Taken from https://stackoverflow.com/a/41135574/3144403
$response_headers = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION,
    function($curl, $header) use (&$response_headers) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) // ignore invalid headers
          return $len;
        $response_headers[strtolower(trim($header[0]))][] = trim($header[1]);
        return $len;
    }
);

// ignore (invalid/expired) SSL, isn't this really the reason for this script? :-D
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response_body = curl_exec($ch);
$response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

// Set the appropriate response status code & headers
http_response_code($response_code);
foreach($response_headers as $name => $values)
    foreach($values as $value)
        header("$name: $value", false);
echo $response_body;
