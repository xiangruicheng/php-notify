<?php
/**
 * 消费队列的进程
 * 每一个类型type的该消费进程可以启动多个
 * 启动方式 php consumer.php type &
 * type值参考Notify的$type_config
 */
include 'notify.php';
$type = $argv[1];
while (true){
    Notify::getInstance()->consumer($type);
}