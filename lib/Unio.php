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
   * undocumented class variable
   *
   * @var string
   **/
  private $auth;


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
   * undocumented function
   *
   * @param string $verb
   * @param string $resource
   * @param array $params
   * @param callable $callback
   * @return object
   **/
  private function request ($verb, $resource, $params, $callback = null) {
    $client = new Client($this->using_spec->api_root);
    if ($verb === 'get') {
      $request = $client->get($resource . '?' . http_build_query($params));
    } else {
      $request = $client->$verb($resource, null, $params);
    }
    if ($callback) {
      $callback($request->send()->json());
    }
    else {
      return $request->send()->json();
    }
  }
}