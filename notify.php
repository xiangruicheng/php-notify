<?php
/**
 * 延迟异步通知类
 */
class Notify
{
    /**
     * mysql数据库相关的配置
     * 1、按照自己的环境做相应的修改
     * 2、tablename字段要与notify.sql中的的建表语句一致
     */
    public $db_config = [
        'host'=>'127.0.0.1',
        'port'=>'3306',
        'username'=>'xxx',
        'password'=>'123456',
        'dbname'=>'xxx',
        'tablename'=>'quenue_log'
    ];

    /**
     * redis相关的配置
     * 根据自己的环境做相应的配置
     */
    public $redis_config = [
        'host' => '127.0.0.1',
        'port' => '6379',
    ];

    /**
     * 消息类型的配置
     * 1、可按照业务需要自行配置
     * 2、每一个类型将会分配一个有序集合和一个list
     * 3、类型只能为int型，取值范围为-128到127
     * @var array
     */
    public $type_config = [1,2];

    /**
     * 存放日志的路径
     * 1、程序不会自动创建相应的目录，需要运行之前创建好，并给定相应的权限
     * 2、日志文件名可在本类的_log()方法中修改，默认为notify_Ymd.log
     * 3、日志只记录了console相关的日志
     * @var string
     */
    public $log_path = 'logs/';

    /**
     * 有序集合和连表KEY的前缀
     * 可以不修改，也可以按照自己的喜好进行修改
     * @var string
     */
    public $quenue_name_prefix = 'xc_delay_quenue_';


    /**
     * 最大延迟通知次数
     * 与$async_time_conf参数的个数相匹配
     * @var int
     */
    public $max_exec_num = 5;

    /**
     * 每次延迟通知的时间间隔
     * @var array
     */
    public $async_time_conf = [
        '1'=>'60000',//1min
        '2'=>'300000',//5min
        '3'=>'1800000',//30min
        '4'=>'3600000',//1h
        '5'=>'7200000',//2h
    ];

    /**
     * 消息的状态，不要对该配置进行修改
     * @var array
     */
    public $msg_status = [
        'delaying'=>'1',//延迟中
        'running'=>'2',//消费中
    ];

    //mysql链接
    public $mysql_conn;
    //redis链接
    public $redis_conn;
    //用于单例的静态常量
    private static $instance;

    /**
     * 析构方法 释放mysql和redis链接
     * @throws \Exception
     */
    public function __destruct()
    {
        $this->get_mysql_conn()->close();
        $this->get_redis_conn()->close();
    }

    /**
     * 单例方法回去本类
     * @return self
     */
    public static function getInstance() {
        if(self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }


    /**
     * 队列生成者
     * @param string $type 类型 参考$type_config
     * @param array $data 数据
     * @return string
     */
    public function producer($type,$data,$notify_url){
        $redis_w = $this->get_redis_conn();
        //产生消息元
        $msg_id = $this->msg_push($type,$data,$notify_url);
        //写入延迟队列(redis有序集合)
        $key = $this->getKey($type);//有序集合队列队列名
        $mircotime = $redis_w->hget($msg_id,'next_time');//执行的时间
        $redis_w->zadd($key,$mircotime,$msg_id);
        return $msg_id;
    }

    /**
     * 队列消费者
     * @param string $type 类型
     */
    public function consumer($type){
        $redis_r = $this->get_redis_conn();
        $redis_w = $this->get_redis_conn();

        $key = $this->getKey($type);//有序集合队列队列名
        $list_key = $this->getListKey($type);//队列名
        $msg_id = $redis_w->lpop($list_key);//消息ID
        if(empty($msg_id)) {
            return false;
        }
        //根据消息ID获取消息元
        if($redis_r->type($msg_id) != 'hash' && $redis_r->type($msg_id)!='5' ) {
            return false;
        }
        $msg = $this->msg_pull($msg_id);
        $body = $msg['body'];
        $url = $msg['notify_url'];
        $this->_log("msg_id={$msg_id}开始消费,body={$body}");

        //发送消息
        $result = $this->json_post($url,$body);
        //处理结果
        if(strtoupper($result)=='SUCCESS') {//成功
            $this->msg_del($msg_id,'1',$result);//删除数据元
            $this->_log("msg_id={$msg_id}消费成功,response={$result}");
        }else{//失败
            if($msg['exec_num']>=$this->max_exec_num) {//超过最大通知次数做错误处理
                $this->msg_del($msg_id,'2',$result);//删除数据元
                $this->_log("msg_id={$msg_id}消费失败,response={$result}");
            }else{//进行延迟
                $next_time = $this->msg_delay($msg_id);//数据元改状态
                $redis_w->zadd($key,$next_time,$msg_id);
                $this->_log("msg_id={$msg_id}进入延迟,response={$result}");
            }
        }
    }

    /**
     * 根据类型将数据写入缓冲队列
     * @param $type
     */
    public function buffer($type)
    {
        $redis_r = $this->get_redis_conn();
        $redis_w = $this->get_redis_conn();
        $key = $this->getKey($type);//有序集合队列队列名
        $list_key = $this->getListKey($type);//队列名
        $msgIdArr = $redis_r->zrange($key,0,0);
        foreach ($msgIdArr as $msg_id) {
            $score = $redis_r->zscore($key,$msg_id);
            if($score<=$this->getMsec()) {
                $redis_w->rpush($list_key,$msg_id);//写入list中
                $redis_w->zrem($key,$msg_id);//从有序集合中移除队列中移除
                $this->_log("msg_id={$msg_id}进入list");
            }
        }
    }

    /**
     * 根据类型获取有序集合的KEY
     * @param $type
     * @return string
     */
    public function getKey($type) {
        return $this->quenue_name_prefix.$type;
    }

    /**
     * 根据类型获取队列的KEY
     * @param $type
     * @return string
     */
    public function getListKey($type) {
        return $this->getKey($type).'_list';
    }


    /**
     * CURL发送消息的方法 此方法按自己的需要可以进行改造
     * @param $url
     * @param $data
     * @return string
     */
    private function json_post($url,$data)
    {
        if(is_array($data)) {
            $data = json_encode($data);
        }
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length:' . strlen($data),
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ];
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_HTTPHEADER,$headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl);
        $errorno = curl_errno($curl);
        curl_close($curl);
        if($errorno) {
            return 'fail';
        }
        return $response;
    }


    private function get_mysql_conn() {
        if($this->mysql_conn === null) {
            $conn = mysqli_connect(
                $this->db_config['host'],
                $this->db_config['username'],
                $this->db_config['password'],
                $this->db_config['dbname'],
                $this->db_config['port']
            );
            if (mysqli_connect_errno($conn)) {
                throw new \Exception("mysql connect fail: " . mysqli_connect_error(),'101');
            }
            $this->mysql_conn = $conn;
        }
        return $this->mysql_conn;
    }

    private function get_redis_conn() {
        if($this->redis_conn === null) {
            $redis = new \Redis();
            $redis->connect($this->redis_config['host'], $this->redis_config['port']);
            $this->redis_conn = $redis;
        }
        return $this->redis_conn;
    }


    /**
     * 添加消息
     * @param string $type 类型
     * @param array $data 数据
     * @return string 消息ID
     */
    public function msg_push($type,$data,$notify_url)
    {
        $msec_time = $this->getMsec();//期望发送的时间（毫秒）
        $msg_id = md5($type.$msec_time.rand(10000,99999));//元数据ID
        //写MYSQL库
        $quenue_log = [
            'id'=>null,
            'msg_id'=>$msg_id,
            'type'=>$type,
            'body'=>addslashes(json_encode($data)),
            'status'=>'0',
            'exec_num'=>'0',
            'notify_url'=>$notify_url,
            'c_time'=>date('Y-m-d H:i:s'),
            'u_time'=>date('Y-m-d H:i:s')
        ];
        $fields = '';
        $values = '';
        foreach ($quenue_log as $key=>$value) {
            $fields .= $key.',';
            $values .= "'$value',";
        }
        $fields = trim($fields,',');
        $values = trim($values,',');
        $sql = "INSERT INTO {$this->db_config['tablename']} ({$fields}) VALUES ($values)";
        $conn = $this->get_mysql_conn();
        $conn->query($sql);

        //写哈希
        $redis = $this->get_redis_conn();
        $redis->hset($msg_id,'msg_id',$msg_id);
        $redis->hset($msg_id,'type',$type);
        $redis->hset($msg_id,'body',json_encode($data));
        $redis->hset($msg_id,'status',1);
        $redis->hset($msg_id,'exec_num',0);
        $redis->hset($msg_id,'c_time',$msec_time);
        $redis->hset($msg_id,'next_time',$msec_time);
        $redis->hset($msg_id,'notify_url',$notify_url);
        $redis->expire($msg_id,86400);
        return $msg_id;
    }

    /**
     * 取出数据；并非删除
     * @param string $msg_id 消息ID
     * @return array|bool Msg对象数组
     */
    private function msg_pull($msg_id){
        $redis_r = $this->get_redis_conn();
        $redis_w = $this->get_redis_conn();
        $msec_time = $this->getMsec();//当前毫秒数
        //获取消息
        $msg = [
            'msg_id'=>$redis_r->hget($msg_id,'msg_id'),
            'type'=>$redis_r->hget($msg_id,'type'),
            'body'=>$redis_r->hget($msg_id,'body'),
            'status'=>$redis_r->hget($msg_id,'status'),
            'exec_num'=>$redis_r->hget($msg_id,'exec_num'),
            'c_time'=>$redis_r->hget($msg_id,'c_time'),
            'next_time'=>$redis_r->hget($msg_id,'next_time'),
            'notify_url'=>$redis_r->hget($msg_id,'notify_url'),
        ];

        //如果是在运行中则返回false
        if($msg['status']==$this->msg_status['running'] || $msg['next_time']>$msec_time) {
            return false;
        }
        //修改消息 执行次数+1 状态改为消费中
        $msg['exec_num'] += 1;
        $redis_w->hset($msg_id,'exec_num',$msg['exec_num']);
        $redis_w->hset($msg_id,'status',$this->msg_status['running']);
        return $msg;
    }

    /**
     * 延迟一次
     * @param $msg_id
     * @return mixed
     */
    private function msg_delay($msg_id){
        $redis_r = $this->get_redis_conn();
        $redis_w = $this->get_redis_conn();
        $c_time = $redis_r->hget($msg_id,'c_time');//创建时间
        $exec_num = $redis_r->hget($msg_id,'exec_num');//执行次数
        //$old_next_time = $redis_r->hget($msg_id,'next_time');//旧的执行时间
        $next_time = $this->async_time_conf[$exec_num]+$c_time;//新的下次执行时间
        //修改消息 状态改为延迟中 并指定下次执行时间
        $redis_w->hset($msg_id,'next_time',$next_time);
        $redis_w->hset($msg_id,'status',$this->msg_status['delaying']);

        //次数+1
        $last_time = date('Y-m-d H:i:s');
        $sql = "update {$this->db_config['tablename']} set exec_num=exec_num+1,last_time='{$last_time}' where msg_id='{$msg_id}'";
        $conn = $this->get_mysql_conn();
        $conn->query($sql);
        return $next_time;
    }

    /**
     * 删除数据元 并持久化
     * @param string $msg_id 消息ID
     * @param string $status 1成功 2失败
     * @param string $result
     */
    private function msg_del($msg_id,$status,$result=''){
        $redis_r = $this->get_redis_conn();
        $redis_w = $this->get_redis_conn();
        $exec_num = $redis_r->hget($msg_id,'exec_num');
        $last_time = $redis_r->hget($msg_id,'next_time');
        //修改库
        $last_time = date('Y-m-d H:i:s',ceil($last_time/1000));
        $sql = "update {$this->db_config['tablename']} set status='{$status}',result='".addslashes($result)."',exec_num='{$exec_num}',last_time='{$last_time}' where msg_id='{$msg_id}'";
        $conn = $this->get_mysql_conn();
        $conn->query($sql);
        //删除哈希
        $redis_w->del($msg_id);
        return true;
    }

    /**
     * 获取当前时间的毫秒数
     * @return float
     */
    private function getMsec()
    {
        list($msec, $sec) = explode(' ', microtime());
        $msectime = (float)sprintf('%.0f',(floatval($msec) + floatval($sec)) * 1000);
        return $msectime;
    }

    private function _log($log)
    {
        $file = $this->log_path.'notify_'.date('Ymd').'.log';
        $log = date('[Ymd His]  ').$log."\n";
        file_put_contents($file,$log,FILE_APPEND);
    }
}