<?php

/**
 * 将有序集合插入链表的进程
 * 该进程有且必须启动一个
 * 启动方式 php buffer.php &
 */
include 'notify.php';
while (true){
    $type_arr = Notify::getInstance()->type_config;
    foreach ($type_arr as $type) {
        Notify::getInstance()->buffer($type);
    }
}