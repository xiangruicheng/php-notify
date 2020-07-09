# php-notify
php+mysql+redis实现的异步延迟通知(类似支付的异步通知)

# 使用步骤
## 1、在mysql数据库中创建notify.sql文件中的表
## 2、配置notify.php文件中的$db_config、$redis_config、$log_path参数
## 3、启动buffer.php进程
## 4、启动n个consumer.php进程 n>=1
## 5、执行producer.php程序插入相应的消息
