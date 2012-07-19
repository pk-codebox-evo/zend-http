<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Http
 */

namespace Zend\Http\PhpEnvironment;

use Zend\Http\Header\Cookie;
use Zend\Http\Request as HttpRequest;
use Zend\Stdlib\Parameters;
use Zend\Stdlib\ParametersInterface;
use Zend\Uri\Http as HttpUri;

/**
 * HTTP Request for current PHP environment
 *
 * @category   Zend
 * @package    Zend_Http
 */
class Request extends HttpRequest
{
    /**
     * Base URL of the application.
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * Base Path of the application.
     *
     * @var string
     */
    protected $basePath;

    /**
     * Actual request URI, independent of the platform.
     *
     * @var string
     */
    protected $requestUri;

    /**
     * PHP server params ($_SERVER)
     *
     * @var ParametersInterface
     */
    protected $serverParams = null;

    /**
     * PHP environment params ($_ENV)
     *
     * @var ParametersInterface
     */
    protected $envParams = null;

    /**
     * Construct
     * Instantiates request.
     */
    public function __construct()
    {
        $this->setEnv(new Parameters($_ENV));

        if ($_GET) {
            $this->setPost(new Parameters($_POST));
        }
        if ($_POST) {
            $this->setQuery(new Parameters($_GET));
        }
        if ($_COOKIE) {
            $this->setCookies(new Parameters($_COOKIE));
        }
        if ($_FILES) {
            // convert PHP $_FILES superglobal
            $files = $this->mapPhpFiles();
            $this->setFiles(new Parameters($files));
        }

        $this->setServer(new Parameters($_SERVER));
    }

    /**
     * Get raw request body
     *
     * @return string
     */
    public function getContent()
    {
        if (empty($this->content)) {
            $requestBody = file_get_contents('php://input');
            if (strlen($requestBody) > 0){
                $this->content = $requestBody;
            }
        }

        return $this->content;
    }

    /**
     * Set cookies
     *
     * Instantiate and set cookies.
     *
     * @param $cookie
     * @return Request
     */
    public function setCookies($cookie)
    {
        $this->getHeaders()->addHeader(new Cookie((array) $cookie));
        return $this;
    }

    /**
     * Set the request URI.
     *
     * @param  string $requestUri
     * @return self
     */
    public function setRequestUri($requestUri)
    {
        $this->requestUri = $requestUri;
        return $this;
    }

    /**
     * Get the request URI.
     *
     * @return string
     */
    public function getRequestUri()
    {
        if ($this->requestUri === null) {
            $this->requestUri = $this->detectRequestUri();
        }
        return $this->requestUri;
    }

    /**
     * Set the base URL.
     *
     * @param  string $baseUrl
     * @return self
     */
    public function setBaseUrl($baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * Get the base URL.
     *
     * @return string
     */
    public function getBaseUrl()
    {
        if ($this->baseUrl === null) {
            $this->setBaseUrl($this->detectBaseUrl());
        }
        return $this->baseUrl;
    }

    /**
     * Set the base path.
     *
     * @param  string $basePath
     * @return self
     */
    public function setBasePath($basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        return $this;
    }

    /**
     * Get the base path.
     *
     * @return string
     */
    public function getBasePath()
    {
        if ($this->basePath === null) {
            $this->setBasePath($this->detectBasePath());
        }

        return $this->basePath;
    }

    /**
     * Provide an alternate Parameter Container implementation for server parameters in this object,
     * (this is NOT the primary API for value setting, for that see getServer())
     *
     * @param  ParametersInterface $server
     * @return Request
     */
    public function setServer(ParametersInterface $server)
    {
        $this->serverParams = $server;

        // This seems to be the only way to get the Authorization header on Apache
        if (function_exists('apache_request_headers')) {
            $apacheRequestHeaders = apache_request_headers();
            if (!isset($this->serverParams['HTTP_AUTHORIZATION'])
                && isset($apacheRequestHeaders['Authorization'])
            ) {
                $this->serverParams->set('HTTP_AUTHORIZATION', $apacheRequestHeaders['Authorization']);
            }
        }

        // set headers
        $headers = array();

        foreach ($server as $key => $value) {
            if ($value && strpos($key, 'HTTP_') === 0) {
                if (strpos($key, 'HTTP_COOKIE') === 0) {
                    // Cookies are handled using the $_COOKIE superglobal
                    continue;
                }
                $name = strtr(substr($key, 5), '_', ' ');
                $name = strtr(ucwords(strtolower($name)), ' ', '-');
            } elseif ($value && strpos($key, 'CONTENT_') === 0) {
                $name = substr($key, 8); // Content-
                $name = 'Content-' . (($name == 'MD5') ? $name : ucfirst(strtolower($name)));
            } else {
                continue;
            }

            $headers[$name] = $value;
        }

        $this->getHeaders()->addHeaders($headers);

        // set method
        if (isset($this->serverParams['REQUEST_METHOD'])) {
            $this->setMethod($this->serverParams['REQUEST_METHOD']);
        }

        // set HTTP version
        if (isset($this->serverParams['SERVER_PROTOCOL'])
            && strpos($this->serverParams['SERVER_PROTOCOL'], self::VERSION_10) !== false
        ) {
            $this->setVersion(self::VERSION_10);
        }

        // set URI
        $uri = new HttpUri();

        // URI scheme
        $scheme = (!empty($this->serverParams['HTTPS'])
                   && $this->serverParams['HTTPS'] !== 'off') ? 'https' : 'http';
        $uri->setScheme($scheme);

        // URI host & port
        $host = null;
        $port = null;
        if (isset($this->serverParams['SERVER_NAME'])) {
            $host = $this->serverParams['SERVER_NAME'];
            if (isset($this->serverParams['SERVER_PORT'])) {
                $port = (int) $this->serverParams['SERVER_PORT'];
            }
        } elseif ($this->getHeaders()->get('host')) {
            $host = $this->getHeaders()->get('host')->getFieldValue();
            // works for regname, IPv4 & IPv6
            if (preg_match('|\:(\d+)$|', $host, $matches)) {
                $host = substr($host, 0, -1 * (strlen($matches[1]) + 1));
                $port = (int) $matches[1];
            }
        }
        $uri->setHost($host);
        $uri->setPort($port);

        // URI path
        $requestUri = $this->getRequestUri();
        if (($qpos = strpos($requestUri, '?')) !== false) {
            $requestUri = substr($requestUri, 0, $qpos);
        }

        $uri->setPath($requestUri);

        // URI query
        if (isset($this->serverParams['QUERY_STRING'])) {
            $uri->setQuery($this->serverParams['QUERY_STRING']);
        }

        $this->setUri($uri);

        return $this;
    }

    /**
     * Return the parameter container responsible for server parameters
     *
     * @see http://www.faqs.org/rfcs/rfc3875.html
     * @return ParametersInterface
     */
    public function getServer()
    {
        if ($this->serverParams === null) {
            $this->serverParams = new Parameters();
        }

        return $this->serverParams;
    }

    /**
     * Provide an alternate Parameter Container implementation for env parameters in this object,
     * (this is NOT the primary API for value setting, for that see env())
     *
     * @param  ParametersInterface $env
     * @return Request
     */
    public function setEnv(ParametersInterface $env)
    {
        $this->envParams = $env;
        return $this;
    }

    /**
     * Return the parameter container responsible for env parameters
     *
     * @return ParametersInterface
     */
    public function getEnv()
    {
        if ($this->envParams === null) {
            $this->envParams = new Parameters();
        }

        return $this->envParams;
    }

    /**
     * Convert PHP superglobal $_FILES into more sane parameter=value structure
     * This handles form file input with brackets (name=files[])
     *
     * @return array
     */
    protected function mapPhpFiles()
    {
        $files = array();
        foreach ($_FILES as $fileName => $fileParams) {
            $files[$fileName] = array();
            foreach ($fileParams as $param => $data) {
                if (!is_array($data)) {
                    $files[$fileName][$param] = $data;
                } else {
                    foreach ($data as $i => $v) {
                        $this->mapPhpFileParam($files[$fileName], $param, $i, $v);
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @param array        $array
     * @param string       $paramName
     * @param int|string   $index
     * @param string|array $value
     */
    protected function mapPhpFileParam(&$array, $paramName, $index, $value)
    {
        if (!is_array($value)) {
            $array[$index][$paramName] = $value;
        } else {
            foreach ($value as $i => $v) {
                $this->mapPhpFileParam($array[$index], $paramName, $i, $v);
            }
        }
    }

    /**
     * Detect the base URI for the request
     *
     * Looks at a variety of criteria in order to attempt to autodetect a base
     * URI, including rewrite URIs, proxy URIs, etc.
     *
     * @return string
     */
    protected function detectRequestUri()
    {
        $requestUri = null;
        $server     = $this->getServer();

        // Check this first so IIS will catch.
        $httpXRewriteUrl = $server->get('HTTP_X_REWRITE_URL');
        if ($httpXRewriteUrl !== null) {
            $requestUri = $httpXRewriteUrl;
        }

        // Check for IIS 7.0 or later with ISAPI_Rewrite
        $httpXOriginalUrl = $server->get('HTTP_X_ORIGINAL_URL');
        if ($httpXOriginalUrl !== null) {
            $requestUri = $httpXOriginalUrl;
        }

        // IIS7 with URL Rewrite: make sure we get the unencoded url
        // (double slash problem).
        $iisUrlRewritten = $server->get('IIS_WasUrlRewritten');
        $unencodedUrl    = $server->get('UNENCODED_URL', '');
        if ('1' == $iisUrlRewritten && '' !== $unencodedUrl) {
            return $unencodedUrl;
        }

        // HTTP proxy requests setup request URI with scheme and host [and port]
        // + the URL path, only use URL path.
        if (!$httpXRewriteUrl) {
            $requestUri = $server->get('REQUEST_URI');
        }

        if ($requestUri !== null) {
            return preg_replace('#^[^:]+://[^/]+#', '', $requestUri);
        }

        // IIS 5.0, PHP as CGI.
        $origPathInfo = $server->get('ORIG_PATH_INFO');
        if ($origPathInfo !== null) {
            $queryString = $server->get('QUERY_STRING', '');
            if ($queryString !== '') {
                $origPathInfo .= '?' . $queryString;
            }
            return $origPathInfo;
        }

        return '/';
    }

    /**
     * Auto-detect the base path from the request environment
     *
     * Uses a variety of criteria in order to detect the base URL of the request
     * (i.e., anything additional to the document root).
     *
     * The base URL includes the schema, host, and port, in addition to the path.
     *
     * @return string
     */
    protected function detectBaseUrl()
    {
        $baseUrl        = '';
        $filename       = $this->getServer()->get('SCRIPT_FILENAME', '');
        $scriptName     = $this->getServer()->get('SCRIPT_NAME');
        $phpSelf        = $this->getServer()->get('PHP_SELF');
        $origScriptName = $this->getServer()->get('ORIG_SCRIPT_NAME');

        if ($scriptName !== null && basename($scriptName) === $filename) {
            $baseUrl = $scriptName;
        } elseif ($phpSelf !== null && basename($phpSelf) === $filename) {
            $baseUrl = $phpSelf;
        } elseif ($origScriptName !== null && basename($origScriptName) === $filename) {
            // 1and1 shared hosting compatibility.
            $baseUrl = $origScriptName;
        } else {
            // Backtrack up the SCRIPT_FILENAME to find the portion
            // matching PHP_SELF.

            $basename = basename($filename);
            $path     = ($phpSelf ? trim($phpSelf, '/') : '');
            $baseUrl  = '/'. substr($path, 0, strpos($path, $basename)) . $basename;
        }

        // Does the base URL have anything in common with the request URI?
        $requestUri = $this->getRequestUri();

        // Full base URL matches.
        if (0 === strpos($requestUri, $baseUrl)) {
            return $baseUrl;
        }

        // Directory portion of base path matches.
        $baseDir = str_replace('\\', '/', dirname($baseUrl));
        if (0 === strpos($requestUri, $baseDir)) {
            return $baseDir;
        }

        $truncatedRequestUri = $requestUri;

        if (false !== ($pos = strpos($requestUri, '?'))) {
            $truncatedRequestUri = substr($requestUri, 0, $pos);
        }

        $basename = basename($baseUrl);

        // No match whatsoever
        if (empty($basename) || false === strpos($truncatedRequestUri, $basename)) {
            return '';
        }

        // If using mod_rewrite or ISAPI_Rewrite strip the script filename
        // out of the base path. $pos !== 0 makes sure it is not matching a
        // value from PATH_INFO or QUERY_STRING.
        if (strlen($requestUri) >= strlen($baseUrl)
            && (false !== ($pos = strpos($requestUri, $baseUrl)) && $pos !== 0)
        ) {
            $baseUrl = substr($requestUri, 0, $pos + strlen($baseUrl));
        }

        return $baseUrl;
    }

    /**
     * Autodetect the base path of the request
     *
     * Uses several criteria to determine the base path of the request.
     *
     * @return string
     */
    protected function detectBasePath()
    {
        $filename = basename($this->getServer()->get('SCRIPT_FILENAME', ''));
        $baseUrl  = $this->getBaseUrl();

        // Empty base url detected
        if ($baseUrl === '') {
            return '';
        }

        // basename() matches the script filename; return the directory
        if (basename($baseUrl) === $filename) {
            return str_replace('\\', '/', dirname($baseUrl));
        }

        // Base path is identical to base URL
        return $baseUrl;
    }
}
