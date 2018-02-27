<?php
namespace Flc\Dysms;

use Flc\Dysms\Request\IRequest;
use Flc\Dysms\Helper;
use ZanPHP\Config\Config;
use ZanPHP\HttpClient\HttpClient;

/**
 * dysms客户端类
 *
 * @author Flc <2017-07-18 23:16:32>
 */
class Client
{
    /**
     * 接口地址
     * @var string
     */
    protected $api_uri = 'http://dysmsapi.aliyuncs.com/';

    /**
     * 回传格式
     * @var string
     */
    protected $format = 'json';

    /**
     * 签名方式
     * @var string
     */
    protected $signatureMethod = 'HMAC-SHA1';

    /**
     * 接口请求方式[GET/POST]
     * @var string
     */
    protected $httpMethod = 'POST';

    /**
     * 配置项
     * @var array
     */
    protected $config = [];

    /**
     * @var static
     */
    private static $_instance = null;

    /**
     * @param array $config
     * @return static
     */
    final public static function instance($config=[])
    {
        return static::singleton($config);
    }

    final public static function singleton($config=[])
    {
        if (null === static::$_instance) {
            static::$_instance = new static($config);
        }
        return static::$_instance;
    }

    /**
     * @param $config
     * @return static
     */
    final public static function getInstance($config=[])
    {
        return static::singleton($config);
    }

    final public static function swap($instance)
    {
        static::$_instance = $instance;
    }

    /**
     * 初始化
     * @param array $config [description]
     */
    public function __construct($config = [])
    {
        if(empty($config)){
            $config = Config::get('sms');
        }
        $this->config = $config;
    }

    /**
     * 请求
     * @param  IRequest $request [description]
     * @return \Generator [type]            [description]
     * @throws \Exception
     */
    public function execute(IRequest $request)
    {
        $action    = $request->getAction();
        $reqParams = $request->getParams();
        $pubParams = $this->getPublicParams();

        $params = array_merge(
            $pubParams,
            ['Action' => $action],
            $reqParams
        );

        // 签名
        $params['Signature'] = $this->generateSign($params);

        // 请求数据
        $resp =yield $this->curl(
            $this->api_uri,
            $params
        );

        $resp = json_decode($resp,true);

        if(empty($resp['error_response'])){
            yield true;
        }else{
            $errorMsg = $resp['error_response']['msg'];

            if(!empty($resp['error_response']['sub_code'])){
                $errorMsg .= '-'.$resp['error_response']['sub_code'];
            }

            if(!empty($resp['error_response']['sub_msg'])){
                $errorMsg .= '-'.$resp['error_response']['sub_msg'];
            }
            throw new \Exception($errorMsg,$resp['error_response']['code']);
        }
    }

    /**
     * 生成签名
     * @param  array  $params 待签参数
     * @return string         
     */
    protected function generateSign($params = [])
    {
        ksort($params);  // 排序

        $arr = [];
        foreach ($params as $k => $v) {
            $arr[] = $this->percentEncode($k) . '=' . $this->percentEncode($v);
        }
        
        $queryStr = implode('&', $arr);
        $strToSign = $this->httpMethod . '&%2F&' . $this->percentEncode($queryStr);

        return base64_encode(hash_hmac('sha1', $strToSign, $this->config['accessKeySecret'] . '&', true));
    }

    /**
     * 签名拼接转码
     * @param  string $str 转码前字符串
     * @return string      
     */
    protected function percentEncode($str)
    {
        $res = urlencode($str);
        $res = preg_replace('/\+/', '%20', $res);
        $res = preg_replace('/\*/', '%2A', $res);
        $res = preg_replace('/%7E/', '~', $res);

        return $res;
    }

    /**
     * 公共返回参数
     * @return array 
     */
    protected function getPublicParams()
    {
        return [
            'AccessKeyId'      => $this->config['accessKeyId'],
            'Timestamp'        => $this->getTimestamp(),
            'Format'           => $this->format,
            'SignatureMethod'  => $this->signatureMethod,
            'SignatureVersion' => '1.0',
            'SignatureNonce'   => uniqid(),
            'Version'          => '2017-05-25',
            'RegionId'         => 'cn-hangzhou',
        ];
    }

    /**
     * 返回时间格式
     * @return string 
     */
    protected function getTimestamp()
    {
        $date = new \DateTime('now',new \DateTimeZone('GMT'));
        return $date->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * curl请求
     * @param  string $url string
     * @param  array|null $postFields 请求参数
     * @return \Generator [type]             [description]
     */
    protected function curl($url, $postFields = null)
    {
        $httpClient = new HttpClient();
        $response = yield $httpClient->postByURL($url,$postFields);
        yield (intval($response->getStatusCode()) === 200) ? $response->getBody() : false;
    }
}