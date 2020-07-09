# php-notify
php+mysql+redis实现的异步延迟通知(类似支付的异步通知)

# 基本原理
1、使用了redis的有序集合实现延时的功能
2、利用reids的list实现同时启动多个消费者同时消费的需求
3、同时在整个过程中用mysql记录数据

# 使用步骤
## 1、在mysql数据库中创建notify.sql文件中的表
## 2、配置notify.php文件中的$db_config、$redis_config、$log_path参数
## 3、启动1个buffer.php进程
## 4、启动n个consumer.php进程 n>=1
## 5、执行producer.php程序插入相应的消息
在这之后就可以在相应的日志文件中看到所插入的消息完整的延迟异步通知流程了
