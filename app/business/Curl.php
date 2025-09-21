<?php

namespace app\business;

class Curl
{

    public static $json = true;
    public static $agreement = 'https://';

    /**
     * @brief                  get请求
     * @param $url             请求的url
     * @param array $param 请求的参数
     * @param int $timeout 超时时间
     * @param int $log 是否启用日志
     * @return mixed
     */
    public static function get($url, $param = array(), $timeout = 10, $log = 1)
    {
        $html = file_get_contents($url);
        $ch = curl_init();
        if (is_array($param)) {
            $url = $url . '?' . http_build_query($param);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // 允许 cURL 函数执行的最长秒数
        $data = curl_exec($ch);
        curl_close($ch);
        if (self::$json) return json_decode($data, true);
        return $data;
    }

    /**
     * @brief                   post请求
     * @param $url /请求的url地址
     * @param array $param 请求的参数
     * @param int $log 是否启用日志
     * @return mixed
     */
    public static function post($url, $param = array(), $header = array(), $timeout = 10, $log = 1)
    {

        $ch = curl_init();
        if (is_array($param)) {
            $urlparam = http_build_query($param);
        } else if (is_string($param)) { //json字符串
            $urlparam = $param;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); //设置超时时间
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //返回原生的（Raw）输出
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_POST, 1); //POST
        curl_setopt($ch, CURLOPT_POSTFIELDS, $urlparam); //post数据
        if ($header) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }
        $data = curl_exec($ch);

        if (!$data){//curl失败的时候 转到请求头上
           return self::send_post($url,$param);
       }

        curl_close($ch);
        if (self::$json) return json_decode($data, true);
        return $data;
    }

    private static function send_post($url, $post_data)
    {
        $postdata = http_build_query($post_data);
        $url = self::$agreement.$url;
        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-type:application/x-www-form-urlencoded',
                'content' => $postdata,
                'timeout' => 1 * 60, // 超时时间（单位:s）,
            )
        );
        $context = stream_context_create($options);
        return file_get_contents($url, false, $context);
    }



    /**
     * 请求信息记录日志
     * @param $ch       curl句柄
     * @param $request  请求参数
     * @param $response 响应结果
     */
    private static function logInfo($ch, $request, $response)
    {
        $info = curl_getinfo($ch);
        $resultFormat = "耗时:[%s] 返回状态:[%s] 请求的url[%s] 请求参数:[%s] 响应结果:[%s] 大小:[%s]kb 速度:[%s]kb/s";
        $resultLogMsg = sprintf($resultFormat, $info['total_time'], $info['http_code'], $info['url'], var_export($request, true), var_export($response, true), $info['size_download'] / 1024, $info['speed_download'] / 1024);
        return $resultLogMsg;
    }
}