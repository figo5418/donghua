<?php
/**
 * Created by PhpStorm.
 * User: Apple
 * Date: 2018/11/1 0001
 * Time: 11:10
 */

namespace App\HttpController;


use App\Utility\Pool\MysqlObject;
use EasySwoole\EasySwoole\Console\ConsoleService;
use EasySwoole\EasySwoole\Logger;
use EasySwoole\EasySwoole\ServerManager;
use EasySwoole\Http\AbstractInterface\Controller;
use EasySwoole\Http\Message\Status;


class Index extends Controller
{
    function index()
    {
        echo 'tests';
        $request = $this->request();

        $data = $request->getRequestParam();
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        $exec_log['content'] = json_encode($data);
        $client->queryBuilder()->insert('exec_log', $exec_log);
        $client->execBuilder();

        if ($data['MsgToUserCode'] == '') {
            $this->writeJson(201, [], '请选择接收者');
            return;
        }
        $receiver_list = explode("^", $data['MsgToUserCode']);


        if (!$receiver_list) {
            $this->writeJson(201, [], '请选择接收者');
            return;
        }

        $data['receiver'] = $data['MsgToUserCode'];
        $data['message'] = $data['MsgContent'];
        $data['sender'] = $data['MsgFromUserCode'];

        unset($data['MsgFromUserCode']);
        unset($data['MsgContent']);
//        unset($data['receiver']);
        unset($data['MsgToUserCode']);

        $data['sendTime'] = time();


        $returnRst = [];


        foreach ($receiver_list as $receiver) {
            $data['receiver'] = $receiver;
            $client->queryBuilder()->where("name", $receiver)->getOne('device');
            $result = $client->execBuilder()[0];

            $server = ServerManager::getInstance()->getSwooleServer();
            $info = $server->getClientInfo($result['fd']);


            if (isset($data['messageId']) && $data['messageId'] > 0) {
                $update = $data;
                unset($update['messageId']);
                $client->queryBuilder()->where("id", $data['messageId'])->update("log", $update);
                $client->execBuilder();
                $returnRst['data']['messageId'] = $data['messageId'];

            } else {
                unset($data['messageId']);
                $data['status'] = 0;
                $client->queryBuilder()->insert('log', $data);
                $client->execBuilder();
                $data['messageId']  =  $client->mysqlClient()->insert_id;;
                $returnRst['type'] = 'message';
                $returnRst['data'] = $data;
                unset($data['messageId']);
                if ($info && $info['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
                    $server->push($result['fd'], json_encode($returnRst));

                }
            }
        }

        $returnRst = isset($returnRst['data']) ? $returnRst['data'] : [];
        $this->writeJson(200, $returnRst, 'success');

        // TODO: Implement index() method.
    }

    function getStatus()
    {
        $request = $this->request();

        $data = $request->getRequestParam();
        $name = isset($data['name']) ? $data['name'] : '';

        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);
        $client->queryBuilder()->where("name", $name)->getOne('device');
        $result = $client->execBuilder()[0];
        $this->writeJson(200, ['status' => $result['status']], 'success');
    }

    function setStatus()
    {
        $request = $this->request();

        $data = $request->getRequestParam();
        $name = isset($data['name']) ? $data['name'] : '';
        $status = isset($data['status']) ? $data['status'] : 0;

        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        $update['status'] = $status;
        $client->queryBuilder()->where("name", $name)->update("device", $update);
        $client->execBuilder();
        $this->writeJson(200, [], 'success');

    }


    function execMessage()
    {
        $request = $this->request();

        $data = $request->getRequestParam();


        $MsgId = $data['MsgId'];

        $data['status'] = $data['MsgStatus'];

        unset($data['MsgStatus']);
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        $client->queryBuilder()->where("MsgId", $MsgId)->getOne('log');
        $result = $client->execBuilder()[0];

        if (!$result) {
            $this->writeJson(201, [], '消息不存在!');
        }

        unset($data['MsgId']);
        $client->queryBuilder()->where("MsgId", $MsgId)->update("log", $data);
        $client->execBuilder();

        $this->writeJson(200, [], 'success');

    }


    function log()
    {
        $request = $this->request();

        $data = $request->getRequestParam();
        $sender = isset($data['sender']) ? $data['sender'] : '';
        $receiver = isset($data['receiver']) ? $data['receiver'] : '';
        $page = isset($data['page']) ? $data['page'] : 1;
        $pageSize = isset($data['pageSize']) ? $data['pageSize'] : 10;


        $limit = (($page - 1) * $pageSize);

        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        if ($sender) {
            $client->queryBuilder()->where(" sender = '$sender' AND `status` !='C' ")->orderBy('sendTime', 'DESC')->get('log', [$limit, $pageSize]);
        } else {
            $client->queryBuilder()->where(" receiver = '$receiver' AND `status` !='C'  ")->orderBy('sendTime', 'DESC')->get('log', [$limit, $pageSize]);
        }
        $result = $client->execBuilder();
        foreach($result as $key=>&$row)
        {
            $row['messageId'] = $row['id'];
        }
        $response = $this->response();

        $response->write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response->withHeader('Content-type', 'application/json;charset=utf-8');
        $response->withStatus(Status::CODE_OK);
    }

    function test()
    {
        $this->response()->write("router test");
    }

    /**
     * request 使用方法
     */
    function requestMethod()
    {
        $request = $this->request();

        $data = $request->getRequestParam();//用于获取用户通过POST或者GET提交的参数（注意：若POST与GET存在同键名参数，则以POST为准）。 示例：
        $param1 = $request->getRequestParam('param1');
        $get = $request->getQueryParams();
        $post = $request->getParsedBody();

        $post_data = $request->getBody();


        $swoole_request = $request->getSwooleRequest();//获取当前的swoole_http_request对象。

        $cookie = $request->getCookieParams();
        $cookie1 = $request->getCookieParams('cookie1');

        $files = $request->getUploadedFiles();
        $file = $request->getUploadedFile('form1');


        $content = $request->getBody()->__toString();
        $raw_array = json_decode($content, true);


        $header = $request->getHeaders();

        $server = $request->getServerParams();

    }


    function isOnline()
    {
        $request = $this->request();

        $data = $request->getRequestParam();
        $name = isset($data['name']) ? $data['name'] : '';
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);


        $client->queryBuilder()->where("name", $name)->getOne('device');
        $result = $client->execBuilder()[0];
        $server = ServerManager::getInstance()->getSwooleServer();
        $info = $server->getClientInfo($result['fd']);


        if ($info && $info['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
            $this->writeJson(200, ['online' => 1], 'success');
            return;
        }
        $this->writeJson(200, ['online' => 0], 'success');

    }


    //
    function HisExec()
    {
        $url = 'http://111.205.6.240/imedical/web/csp/BSP.MSG.SRV.HTTPInterface.cls';
        $request = $this->request();

        $data = $request->getRequestParam();

        $request_data = $data;

        $request_data['CacheUserName'] = 'dhwebservice';
        $request_data['CachePassword'] = 'dhwebservice';
        $request_data['CacheNoRedirect'] = 1;
        $request_data['act'] = 'ExecMessage';
        $MsgId = $data['MsgId'];

        $msgStatus = $data['MsgStatus'];
        if ($msgStatus == "Y") {
            $data['status'] = 3;
        }
        if ($msgStatus == "N") {
            $data['status'] = 4;
        }

        unset($data['MsgStatus']);
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        $client->queryBuilder()->where("MsgId", $MsgId)->getOne('log');
        $result = $client->execBuilder()[0];

        $client->queryBuilder()->where("name", $result['receiver'])->getOne('device');
        $device_info = $client->execBuilder()[0];
        if (!$result) {
            $this->writeJson(201, [], '消息不存在!');
            return;
        }

        unset($data['MsgId']);
        $client->queryBuilder()->where("MsgId", $MsgId)->update("log", $data);
        $client->execBuilder();


        //请求东华接口
        $execRst = $this->curl_post($url, $request_data);
        $execRst = json_decode($execRst, true);


        //推送ws数据给设备
        $returnRst['type'] = 'message';
        $returnRst['data'] = $data;
        $returnRst['data']['messageId'] = $result['id'];
        $server = ServerManager::getInstance()->getSwooleServer();
        $info = $server->getClientInfo($device_info['fd']);
        if ($info && $info['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
            $server->push($result['fd'], json_encode($returnRst));
        }

        $this->writeJson(200, $execRst, 'success');

    }


    function tests()
    {
        $request = $this->request();

        $data = $request->getRequestParam();
        $response = $this->response();

        $response->write(json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response->withHeader('Content-type', 'application/json;charset=utf-8');
        $response->withStatus(Status::CODE_OK);

    }


    public
    function curl_post($url, $data = array())
    {

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

        // POST数据

        curl_setopt($ch, CURLOPT_POST, 1);

        // 把post的变量加上

        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $output = curl_exec($ch);

        var_dump($output);
        curl_close($ch);

        return $output;

    }


    function onException(\Throwable $throwable): void
    {
        Logger::getInstance()->log($throwable->getMessage());
    }


    /**
     * response使用方法
     */
    function responseMethod()
    {
        $response = $this->response();
        $swoole_response = $response->getSwooleResponse();
        $response->withStatus(Status::CODE_OK);
        $response->write('response write.');
        $response->setCookie('cookie name', 'cookie value', time() + 120);
        $response->redirect('/test');
        $response->withHeader('Content-type', 'application/json;charset=utf-8');

        if ($response->isEndResponse() == $response::STATUS_NOT_END) {
            $response->end();
        }
    }


    protected
    function onRequest(?string $action): ?bool
    {
        if (0/*auth_fail*/) {
            $this->response()->write('auth fail');
            return false;
        } else {
            return true or null;
        }
    }

}
