<?php
/**
 * Created by PhpStorm.
 * User: Apple
 * Date: 2018/11/1 0001
 * Time: 14:42
 */

namespace App\WebSocket;

use EasySwoole\EasySwoole\ServerManager;
use EasySwoole\EasySwoole\Task\TaskManager;
use EasySwoole\Socket\AbstractInterface\Controller;

/**
 * Class Index
 *
 * 此类是默认的 websocket 消息解析后访问的 控制器
 *
 * @package App\WebSocket
 */
class Index extends Controller
{
    function hello()
    {
        $this->response()->setMessage('call hello with arg:' . json_encode($this->caller()->getArgs()));
    }

    function tests()
    {
        $this->response()->setMessage('test fd ===' . json_encode($this->caller()->getClient()->getFd()));
    }

    public function who()
    {
        $this->response()->setMessage('your fd is ' . $this->caller()->getClient()->getFd());
    }

    function login()
    {
        $data = $this->caller()->getArgs();
        $uuid = $data['uuid'];
        $fd = $this->caller()->getClient()->getFd();

        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        $client->queryBuilder()->where('uuid', $uuid)->update('device', ['fd' => $fd]);

        $client->execBuilder();
        $returnRst['type'] = 'login_success';
        $returnRst['data'] = $uuid . "登陆成功";
        $this->response()->setMessage(json_encode($returnRst));
    }



    function device_init()
    {
        $data = $this->caller()->getArgs();
        $uuid = $data['uuid'];
        $returnRst['type'] = 'device_init';


        if(!$data['uuid'])
        {
            $returnRst['data'] = '暂无此设备!';
            $this->response()->setMessage(json_encode($returnRst));
            return;
        }
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);
        $client->queryBuilder()->where("uuid = '$uuid' ")->getOne('device');

        $device = $client->execBuilder()[0];


        if(!$device)
        {
                $returnRst['error'] = -1;
                $returnRst['data'] = '暂无此设备!';
                $this->response()->setMessage(json_encode($returnRst));
                return;

        }

        $client->queryBuilder()->where("uuid",$uuid,'!=')->get('device');
        $other = $client->execBuilder();

        $returnRst['data']['your_device'] =  $device;
        $returnRst['data']['other_device'] =$other;




        $returnRst['type'] = 'device_init';

        $this->response()->setMessage(json_encode($returnRst));

    }



    function send_message()
    {
        $data = $this->caller()->getArgs();
        print_r($data);
        $receiver = $data['receiver'];

        $data['sendTime'] = time();
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        $sender_fd = $this->caller()->getClient()->getFd();
        $server = ServerManager::getInstance()->getSwooleServer();


//        if(!$receiver)
//        {
//            $returnRst['error'] = -1;
//            $returnRst['data'] = '请选择接收者!';
//            $server->push($this->caller()->getClient()->getFd(), json_encode($returnRst));
//        }

        try {
            if ($receiver) {
                $client->queryBuilder()->where("uuid", $receiver)->getOne('device');
                $device = $client->execBuilder();
                if($device){
                    $device = $device[0];
                }
//            var_dump($data);
                $update = $data;
                $returnRst['type'] = 'message';
                $returnRst['data'] = $data;
                if (isset($data['messageId']) && $data['messageId'] > 0) {
                    $returnRst['data']['messageId'] = $data['messageId'];

                    unset($update['messageId']);
                    if(isset($update['department']))
                    {
                        unset($update['department']);
                    }
                    if(isset($update['title']))
                    {
                        unset($update['title']);
                    }
                    if(isset($update['sender']))
                    {
                        $update['sender'] = strtoupper($update['sender']);
                    }
                    $client->queryBuilder()->where("id", $data['messageId'])->update("log", $update);
                    $client->execBuilder();

                } else {

                    unset($data['messageId']);
                    $data['status'] = 0;

                    $client->queryBuilder()->insert('log', $data);
                    $client->execBuilder();
                    $returnRst['data']['messageId'] = $client->mysqlClient()->insert_id;
                    $server->push($device['fd'], json_encode($returnRst));

                }

            } else {
                $returnRst['type'] = 'message';
                $returnRst['data'] = $data;
                //广播消息
                $conn_list = $server->connection_list(0, 30);
                if (empty($conn_list)) {
                    return;
                }
                $start = end($conn_list);
                foreach ($conn_list as $fd) {
                    if ($fd != $sender_fd) {

                        $info = $server->getClientInfo($fd);
                        /** 判断此fd 是否是一个有效的 websocket 连接 */
                        if ($info && $info['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
                            $data['receiver'] = $this->getNameByFd($fd);
                            if (isset($data['messageId']) && $data['messageId'] > 0) {
//                            $returnRst['data']['messageId'] = $data['messageId'];
//
//                            unset($update['messageId']);
//                            $client->queryBuilder()->where("id",$data['messageId'])->update("log",$update);
//                            $client->execBuilder();
                            } else {
                                if ($data['receiver']) {
                                    unset($data['messageId']);
                                    $data['status'] = 0;

                                    $client->queryBuilder()->insert('log', $data);
                                    $client->execBuilder();
                                    $returnRst['data']['messageId'] = $client->mysqlClient()->insert_id;
                                    $returnRst['data']['receiver'] = $data['receiver'];
                                    $server->push($fd, json_encode($returnRst));
                                }
                            }


                        }
                    }
                }
            }
        }catch (\Exception $e){
            print_r($e->getMessage());
            print_r($e->getLine());

        }
    }

    public function getNameByFd($fd)
    {
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);

        $client->queryBuilder()->where("fd",$fd)->getOne('device');
        $result = $client->execBuilder();
        if($result)
        {
            return $result[0]['uuid'];
        }
    }

    public function init()
    {
//        $uuid =    $this->request()->getRequestParam('uuid');
        $instance = \EasySwoole\EasySwoole\Config::getInstance();
        $config = new \EasySwoole\Mysqli\Config($instance->getConf('MYSQL'));
        $client = new \EasySwoole\Mysqli\Client($config);
        $client->queryBuilder()->get('device');
        $data = $client->execBuilder();

        $returnRst['type'] = 'init';
        $returnRst['data'] = $data;
        $this->response()->setMessage(json_encode($returnRst));
    }
    function ping()
    {
        $this->response()->setMessage("pong");

    }

    function delay()
    {
        $this->response()->setMessage('this is delay action');
        $client = $this->caller()->getClient();

        // 异步推送, 这里直接 use fd也是可以的
        TaskManager::getInstance()->async(function () use ($client) {
            $server = ServerManager::getInstance()->getSwooleServer();
            $i = 0;
            while ($i < 5) {
                sleep(1);
                $server->push($client->getFd(), 'push in http at ' . date('H:i:s'));
                $i++;
            }
        });
    }
}
