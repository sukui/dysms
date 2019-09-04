<?php
namespace Flc\Dysms;

use Flc\Dysms\Request\IRequest;
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
        $resp = yield $this->curl(
            $this->api_uri,
            $params
        );

        $code = intval($resp->getStatusCode());

        if($code === 200){
            $content =   $resp->getBody();
            $data = json_decode($content,true);
            if($data['Code'] == 'OK'){
                yield true;
            }else{
                $message = $this->getError($data['Code']);
                throw new \Exception("{$message},{$data['Message']}");
            }
        }else{
            throw new \Exception("网关请求错误，code:{$code}",$code);
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
        yield $httpClient->postByURL($url,$postFields);
    }

    /**
     * 获取错误信息
     * @param $error_code
     * @return string
     */
    protected function getError($error_code){
        $message = "未知错误";
        switch ($error_code){
            case 'isv.OUT_OF_SERVICE':
                $message = "业务停机";
                break;
            case 'isv.PRODUCT_UNSUBSCRIBE':
                $message = "产品服务未开通";
                break;
            case 'isv.ACCOUNT_NOT_EXISTS':
                $message = "账户信息不存在";
                break;
            case 'isv.ACCOUNT_ABNORMAL':
                $message = "账户信息异常";
                break;
            case 'isv.SMS_TEMPLATE_ILLEGAL':
                $message = "短信模板不合法";
                break;
            case 'isv.SMS_SIGNATURE_ILLEGAL':
                $message = "短信签名不合法";
                break;
            case 'isv.MOBILE_NUMBER_ILLEGAL':
                $message = "手机号码格式错误";
                break;
            case 'isv.MOBILE_COUNT_OVER_LIMIT':
                $message = "手机号码数量超过限制";
                break;
            case 'isv.TEMPLATE_MISSING_PARAMETERS':
                $message = "短信模板变量缺少参数";
                break;
            case 'isv.INVALID_PARAMETERS':
                $message = "参数异常";
                break;
            case 'isv.BUSINESS_LIMIT_CONTROL':
                $message = "发送短信过于频繁限制发送";
                break;
            case 'isv.INVALID_JSON_PARAM':
                $message = "JSON参数不合法";
                break;
            case 'isv.BLACK_KEY_CONTROL_LIMIT':
                $message = "触发关键字黑名单";
                break;
            case 'isv.PARAM_NOT_SUPPORT_URL':
                $message = "不支持url为变量";
                break;
            case 'isv.PARAM_LENGTH_LIMIT':
                $message = "变量长度受限";
                break;
            case 'isv.AMOUNT_NOT_ENOUGH':
                $message = "短信账户余额不足";
                break;
            case 'isv.DAY_LIMIT_CONTROL':
                $message = "触发日发送限额";
                break;
            case 'isv.MONTH_LIMIT_CONTROL':
                $message = "触发月发送限额";
                break;
            case 'isv.SMS_SIGN_ILLEGAL':
                $message = "短信签名非法";
                break;
            default:

        }
        return $message;
    }
}