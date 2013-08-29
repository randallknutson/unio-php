<?php
use Guzzle\Http\Client;

/*
 * This file is part of the Unio package.
 *
 * (c) Andrew Weir <andru.weir@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (!defined('DS')) {
  define('DS', DIRECTORY_SEPARATOR);
}

/**
 * Port of unio node.js package in PHP.
 *
 * @package Unio
 * @author Andrew Weir <andru.weir@gmail.com>
 **/
class Unio {

  /**
   * Allowed HTTP verbs
   *
   * @var array
   **/
  private $verbs = ['get', 'patch', 'post', 'put', 'delete'];

  /**
   * Array of available specs
   *
   * @var array
   **/
  private $specs = [];

  /**
   * Spec directory
   *
   * @var string
   **/
  private $spec_dir;

  /**
   * Current spec being used for request
   *
   * @var string
   **/
  private $using_spec;

  /**
   * Auth settings
   *
   * @var array
   **/
  private $auth = FALSE;

  /**
   * Oauth settings
   *
   * @var array
   */
  private $oauth = FALSE;

  /**
   * Constructor function. Pass in the directory of the specs.
   *
   * @param string $spec_dir
   * @return void
   **/
  public function __construct ($spec_dir = null) {
    if (!$spec_dir) {
      $this->spec_dir = dirname(dirname(__FILE__)) . DS . 'specs';
    } else {
      $this->spec_dir = $spec_dir;
    }
    foreach (glob($this->spec_dir . DS . '*.json') as $spec_file) {
      $this->specs[basename($spec_file, '.json')] = json_decode(file_get_contents($spec_file));
    }
  }

  /**
   * Generic call function. This will map to the HTTP verbs
   *
   * @param string $name
   * @param array $args
   * @return object
   **/
  public function __call ($name, $args) {
    if (!in_array($name, $this->verbs)) {
      throw new \Exception("Method missing", 1);
    }
    if (!$this->using_spec) {
      throw new \Exception("Need to set spec with useSpec()", 1);
    }
    $verb     = $name;
    $resource = $args[0];
    $params   = $args[1];
    $callback = $args[2];

    return $this->request($verb, $resource, $params, $callback);
  }

  /**
   * Add a spec to this object.
   *
   * @param object $spec
   * @return object
   **/
  public function addSpec ($spec) {
    if ($this->specs[$spec->name]) {
      throw new \Exception('spec with this name already exists.', 1);
    }
    $this->specs[$spec->name] = $spec;

    return $this;
  }

  /**
   * Set basic authentication for requests
   *
   * @param array $auth = array(
   *   'user' => '***',
   *   'pass' => '***'
   *  );
   * @return object
   */
  public function setAuth ($auth) {
    $this->auth = $auth;

    return $this;
  }

  /**
   * Set basic authentication for requests

   * @param array $oauth = array(
   *    'consumer_key'  => '***',
   *    'consumer_secret' => '***',
   *    'token'       => '***',
   *    'token_secret'  => '***'
   *   );
   * @return object
   */
  public function setOauth ($oauth) {
    $this->oauth = $oauth;

    return $this;
  }

  /**
   * Select which spec to use.
   *
   * @param string $spec_name
   * @return object
   **/
  public function useSpec ($spec_name) {
    if (!isset($this->specs[$spec_name])) {
      throw new \Exception("Cannot use `" . $spec_name . "`. Call unio.spec() to add this spec before calling .use().", 1);
    }
    $this->using_spec = $this->specs[$spec_name];
    return $this;
  }

  /**
   * Send a request to the REST API. This is called from __call()
   *
   * @param string $verb
   * @param string $resource
   * @param array $params
   * @param callable $callback
   * @return object
   **/
  private function request ($verb, $resource, $params, $callback = FALSE) {
    $specResource = $this->findMatchingResource($verb, $resource);

    if (!$specResource) {
      throw new \Exception(ucfirst($verb) . ' ' . $resource . " not supported for API `" . $this->usingSpec->name . "`. Make sure the spec is correct, or `.use()` the correct API.", 1);
    }

    foreach($specResource->params as $keyName => $required) {
      if ($required == 'required' && !isset($params[$keyName])) {
        throw new \Exception("Invalid request: params object must have `" . $keyName . "`. It is listed as a required parameter in the spec.", 1);
      }
    }

    // if /:params are used in the resource path, populate them
    $matches = array();
    if (preg_match_all('/\/:(\w+)/', $resource, $matches)) {
      foreach($matches as $paramName) {
        if (!isset($params[$paramName])) {
          throw new \Exception('Params object is missing a required parameter from url path: ' . $paramName . '.', 1);
        }
        $resource = str_replace('/:' . $paramName, $params[$paramName], $resource);
        unset($params[$paramName]);
      }
    }

    $client = new Client($this->using_spec->api_root);

    // Check for auth params
    if ($this->auth) {
      $client->setAuth($this->auth['user'], $this->auth['pass']);
    }

    // Check for Oauth Params
    if ($this->oauth) {
      $client->addSubscriber(new Guzzle\Plugin\Oauth\OauthPlugin($this->oauth));
    }

    if (in_array($verb, array('post', 'put', 'patch'))) {
      $request = $client->$verb($resource, null, $params);
    }
    elseif ($verb == 'get') {
      $request = $client->$verb($resource . '?' . http_build_query($params));
    }
    elseif ($verb == 'delete') {
      $request = $client->$verb($resource);
    }
    else { // Just in case.
      throw new \Exception('Trying to call an invalid verb `' . $verb . '`', 1);
    }

    $response = $request->send();
    if ($callback) {
      $callback($response->json());
    }
    else {
      return $response->json();
    }
  }

  /**
   * Find the first matching API resource for `verb` and `resource`
   * from the spec we are currently using.
   *
   * @param  {String} verb       HTTP verb; eg. 'get', 'post'.
   * @param  {String} resource   user's requested resource.
   * @return {Object}            matching resource object from the spec.
   */
  function findMatchingResource($verb, $resource) {
    $specResource = null;
    $resourceCandidates = $this->usingSpec->resources;

    // find the first matching resource in the spec, by
    // checking the name and then the path of each resource in the spec
    foreach($resourceCandidates as $index => $candidate) {
      $normName = $this->normalizeUri($candidate->name);
      $normPath = $this->normalizeUri($candidate->path);

      // check for a match in the resource name or path
      $nameMatch = preg_match($normName, $resource) || preg_match($normPath, $resource);

      // check that the verbs allowed with this resource match `verb`
      $verbMatch = in_array($verb, $candidate->methods);

      if ($nameMatch && $verbMatch) {
        return $resourceCandidates[$index];
      }
    }
    return FALSE;
  }

  /**
   * Normalize `uri` string to its corresponding regex string.
   * Used for matching unio requests to the appropriate resource.
   *
   * @param  {String} uri
   * @return {String}
   */
  function normalizeUri($uri) {
    // normalize :params
    // string forward slash -> regex match for forward slash
    $normUri = preg_replace(array('/:w+/g', '/\//g'), array(':w+', '\\/'), $uri);
    return '^' . $normUri . '$';
  }
}