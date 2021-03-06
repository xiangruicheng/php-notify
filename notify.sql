CREATE TABLE `quenue_log` (
`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
`msg_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '消息ID',
`type` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '类型',
`body` VARCHAR(4096) NOT NULL DEFAULT '' COMMENT '消息体' ,
`notify_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '异步通知地址',
`status` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '状态 0初始化 1成功 2失败',
`result` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '返回结果',
`exec_num` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '执行次数',
`last_time` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '最后执行时间',
`c_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '创建时间',
`u_time` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '更新时间',
PRIMARY KEY (`id`) USING BTREE,
UNIQUE INDEX `msg_id` (`msg_id`) USING BTREE
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB
;