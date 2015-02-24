# RollingCurlX

Rolling Curl X is a fork of Rolling Curl wrapper cURL Multi. It aims at making concurrent http requests in PHP as easy as possible.


####License
MIT

#### Version
0.9.0

for PHP 5.4+

##How to Use
Using this class is very easy.

First initialize class with the maximum number of concurrent requests you want open at a time.
All requests after this will be queued until one completes.

```php
$RCX = new RollingCurlX(10);
```

Next add a request to the queue
```php
$url = 'http://www.google.com/search?q=apples';
$post_data = ['user' => 'bob', 'token' => 'dQw4w9WgXcQ']; //set to NULL if not using POST
$user_data = ['foo', $whatever];
$options = [CURLOPT_FOLLOWLOCATION => false];

$RCX->addRequest($url, $post_data, 'callback_functn', $user_data, $options, $headers);
```

The callback function should look like this:
```php
function callback_functn($response, $url, $request_info, $user_data, $time) {
    $time; //how long the request took in milliseconds (float)
    $request_info; //returned by curl_getinfo($ch)
}
```

Send the requests. Blocks until all requests complete or timeout.
```php
$RCX->execute();
```

See? Easy. Thats pretty much it for a simple request.

There's more if you need it though...
```php
//Set a timeout on all requests:
$RCX->setTimeout(3000); //in milliseconds

//To set options for all requests(will be overridden by individual request options):
$RCX->setOptions([$curl_options]);

//To do the same with http headers:
$RCX->setHeaders(['Content-type: application/xml', 'Authorization: gfhjui']);
```

### Issues
If you find any issues please let me know.

Enjoy.

http://www.github.com/marcushat/rollingcurlx
