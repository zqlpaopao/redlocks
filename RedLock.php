<?php

class RedLock
{
    private $retryDelay;
    private $retryCount;
    private $clockDriftFactor = 0.01;

    private $quorum;

    private $servers = array();
    private $instances = array();
    
    /**
     * 初始化redis
     * @param array $servers redis实例列表
     * @param type $retryDelay 重试延时时间,过多少秒尝试重新获取锁
     * @param type $retryCount 重试次数
     */
    function __construct(array $servers, $retryDelay = 200, $retryCount = 3)
    {
        $this->servers = $servers;//服务器多节点

        $this->retryDelay = $retryDelay;//重试延时时间
        $this->retryCount = $retryCount;//重试次数
        
        //获取锁最少机器数才认为成功.最少加锁机器数,全部加还是机器一半以上
        $this->quorum  = min(count($servers), (count($servers) / 2 + 1));
    }
    
    /**
     * 获取唯一锁
     * @param type $resource 获取锁的key
     * @param type $ttl 锁的生存时间 毫秒级别
     * @return boolean
     */
    public function lock($resource, $ttl)
    {
        //链接redis
        $this->initInstances();
        
        //获取唯一标识,返回值不具备唯一性,可使用雪花算法进行优化,获取唯一值
        $token = uniqid();

        $retry = $this->retryCount;//重试次数

        do {
            $n = 0;//redis实例加锁成功个数

            $startTime = microtime(true) * 1000;//返回时间戳微妙数,浮点型1573451335739.5
            
            //循环redis实例进行加锁
            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $n++;//加锁成功+1
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            //机器之间的时钟漂移和程序执行的时钟漂移
            $drift = ($ttl * $this->clockDriftFactor) + 2;
            
            //锁剩余有效时间  ttl - 加锁时间 - 时钟漂移时间
            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;
            
            //加锁成功redis实例 > 一半 并且 锁的有效剩余时间 > 0
            if ($n >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime, //锁的剩余有效时间
                    'resource' => $resource, //锁的key
                    'token'    => $token, //锁的唯一value
                ];

            } else {
                //redis 成功个数小于一半,或者锁的有效剩余时间<=0,进行解锁时间
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }

            // Wait a random delay before to retry
            //加锁失败重试等待时间,100 毫秒或者200毫秒,此时间自己控制
            $delay = mt_rand(floor($this->retryDelay / 2), $this->retryDelay);
            usleep($delay * 1000);
            
            //初始值为3 $retry = $this->retryCount;//重试次数
            $retry--;

        } while ($retry > 0);//重试2次,进来就获取一次,重试2次失败就放弃

        return false;
    }
    
    /**
     * 解锁
     * @param array $lock
     */
    public function unlock(array $lock)
    {
        $this->initInstances();
        $resource = $lock['resource'];
        $token    = $lock['token'];

        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    private function initInstances()
    {
        if (empty($this->instances)) {
            foreach ($this->servers as $server) {
                list($host, $port, $timeout) = $server;
                $redis = new \Redis();
                $redis->connect($host, $port, $timeout);
                $redis -> auth('Aspire@dis');
                $this->instances[] = $redis;
            }
        }
    }
    
    /**
     * redis 实例进行加锁
     * @param type $instance 单个redis实例
     * @param type $resource 分布式锁的key
     * @param type $token 分布式锁的唯一值
     * @param type $ttl 分布式锁的ttl
     * @return type bool
     */
    private function lockInstance($instance, $resource, $token, $ttl)
    {
        print_r( $instance->set($resource, $token, ['NX', 'PX' => $ttl]));
        return $instance->set($resource, $token, ['NX', 'PX' => $ttl]);
    }
    
    /**
     * 
     * @param type $instance redis 实例节点
     * @param type $resource 锁的key
     * @param type $token 当前加锁的client的锁
     * @return type
     */
    private function unlockInstance($instance, $resource, $token)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';
        return $instance->eval($script, [$resource, $token], 1);
    }
    
    /**
     * 为分布式锁续期
     * @param type $redis redis 实例列表
     * @param type $key 分布式锁id
     * @param type $ttl 分布式锁有效期
     * @return boolean|int
     */
    public static function watchDogToLock($redis,$key,$ttl){
//        $this->initInstances();
        $lock = $redis->lock($key, $ttl);
        print_r($lock);
        echo 9999;
        /**
            [validity] => 987.166015625
            [resource] => test11a
            [token] => 5dc9292a6150e
         */
        if(!$lock){
            echo 88888;
            return false;
        }
        if($lock){
            $pid = pcntl_fork();
            if(-1 == $pid){
                return false;
            }elseif(0 == $pid){
                //程序执行
//                error_log(print_r("zijincheng\n",1),3,'/tmp/zi.log');
               
                    echo '子进程';
                    sleep(20);
                    exit;
                
            }else{
                //父进程监控,子进程结束,返回子进程号,子进程存在,为子进程续期为当前时间,一般的时间为其续期
                while(!pcntl_waitpid($pid,$status,WNOHANG)){
                    echo '监控'.PHP_EOL;
                    sleep(1);
                    $renewal = intval($lock['validity'] / 200) ;
                    $renewal = 1000;
                    if($renewal  > $redis->clockDriftFactor){
                        //进行续期
                        $aa = $redis -> renewal($lock['resource'],$lock['token'],$renewal);
//                        print_r($aa);exit;
                        
                    }                   
                }
                $exit_sig = pcntl_wtermsig($status);
                if(0 == $exit_sig ){
//                    $redis -> unlock($lock);
                    return 0;
                }else{
//                    $redis -> unlock($lock);
                    return $exit_sig;
                }
            }
        }
    }
    

    /**
     * 为子进程存在的续期
     * @param type $resource 分布式锁
     * @param type $token 唯一值
     * @param type $renewal 续期时间为ttl 一半
     * @return type
     */
    public function renewal($resource,$token,$renewal){
//        print_r($renewal).PHP_EOL;
         $this->initInstances();
        foreach( $this->instances as $red_val){
//            print_r($this->instances);
            $script = '              
                if (redis.call("GET", KEYS[1]) == ARGV[1])  then
                    redis.call("expire", KEYS[1],ARGV[2])
                    return redis.call("ttl", KEYS[1])
                else
                    redis.call("set", KEYS[1],ARGV[1])
                    redis.call("expire", KEYS[1],ARGV[2])
                    return 8
                end
                ';
            //$script = 'return  {KEYS[1],KEYS[2],ARGV[1],ARGV[2]}';
//            $script = 'return  redis.call("GET", KEYS[1])';

            return $red_val->eval($script, [$resource, null,$token,$renewal], 2);
            /**
            $redis = new Redis(); #实例化redis类
            $redis->connect('127.0.0.1'); #连接服务器
 
            $lua = <<<SCRIPT
                return {KEYS[1],KEYS[2],ARGV[1],ARGV[2]}
            SCRIPT;
            //对应的redis命令如下 eval "return {KEYS[1],KEYS[2],ARGV[1],ARGV[2]}" 2 key1 key2 first second
            $s = $redis->eval($lua,array('key1','key2','first','second'),2);
            var_dump($s);

            $redis->close(); #关闭连接
            ?>

            这个执行的对应命令如下：

            eval "return {KEYS[1],KEYS[2],ARGV[1],ARGV[2]}" 2 key1 key2 first second

            解释： "return {KEYS[1],KEYS[2],ARGV[1],ARGV[2]}" 是被求值的 Lua 脚本，数字 2 指定了键名参数的数量， key1 和 key2 是键名参数，分别使用 KEYS[1] 和 KEYS[2] 访问，而最后的 first 和 second 则是附加参数，可以通过 ARGV[1] 和 ARGV[2] 访问它们。

            PHP中使用redis拓展执行脚本时，eval方法的参数 3个，第一个是脚本代码，第二个是一个数组，参数数组，第三个参数是个整数，表示第二个参数中的前几个是key参数，剩下的都是附加参数
            */
        }

    }
    
    function get($resource,$token){
        $this->initInstances();
//        print_r($redis);
        foreach( $this->instances as $red_val){
             $script = '
                local times = redis.call("ttl",KEYS[1])
                return times
            ';
            return $red_val->eval($script, [$resource, $token], 1);
        }
    }
    
}
//http://ifeve.com/redis-lock/
//https://blog.csdn.net/ice24for/article/details/86177152
//https://blog.csdn.net/g6u8w7p06dco99fq3/article/details/88587374