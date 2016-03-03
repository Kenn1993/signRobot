# PHP+Linux计划任务实现签到机器人
[![Support](https://img.shields.io/badge/support-PHP-blue.svg?style=flat)](http://www.php.net/)
[![Support](https://img.shields.io/badge/support-ThinkPHP-red.svg?style=flat)](http://www.thinkphp.cn/)
[![Support](https://img.shields.io/badge/support-redis-green.svg?style=flat)](http://redis.io/)
[![Support](https://img.shields.io/badge/support-perl-yellow.svg?style=flat)](www.perl.org)
##需求简述
1.一批内部帐号，每天随机产生200~300的用户进行签到行为。

2.这部分帐号中按以下行为进行签到：

a.0点至6点，2%的帐号进行签到。签到时间随机。

b.6点到12点，10%的帐号进行签到。签到时间随机。

c.12点到18点，15%的帐号进行签到。签到时间随机。

d.18点到24点，23%的帐号进行签到。签到时间随机。

3.机器人可以在后台关闭。



## 原理介绍
1. 编写php接口实现抽取指定数量随机内部帐号id和生成随机签到时间打包存到redis；
2. 编写php接口实现根据当前时间获取redis的数据，入库签到；
3. 设置Linux计划任务，自动指定时间去调用sh脚本，sh脚本再调用perl脚本，perl调用步骤1的接口；
4. 设置Linux计划任务，每分钟去调用sh脚本，sh脚本再调用perl脚本，perl调用步骤2的接口；


## 代码详情
### 1、编写php接口实现抽取指定数量随机内部帐号id和生成随机签到时间打包存到redis
	 /*
     * 随机抽取该时间段的内部帐号并生成随机时间
     */
    public function getRandUid(){
        //在库里设置一个字段控制机器人的开关，每次调用都判断是否开启
        $robotObj = M('robot.robot_main',null,'robot');
        $find = $robotObj->where(array('id'=>'1'))->find();//签到机器人id为1
        if($find['robot_status']==1){
            //实例化redis
            $redisObj = new redis();
            $redisObj->connect('127.0.0.1', 6379);
            //获取当前时间（小时）
            $t = date('H',time());
            //每日0点先抽取200-300个内部帐号存入redis
            if($t==0){
                $uid=array();
                $rand = mt_rand(200,300);
                $i=1;
                while($i<=$rand){
                    $uid_rand = mt_rand(507,1201);//内部帐号的id是507-1201
                    if(in_array($uid_rand,$uid)){//去重
                        continue;
                    }else{
                        $uid[]=$uid_rand;
                        $i++;
                    }
                }
                //把uid存入redis
                $signRobotUidCacheKey = 'signRobotUidCacheKey';
                $redisObj->set($signRobotUidCacheKey,json_encode($uid));
            }
            //每日0点、6点、12点、18点、24点都分配该时间段签到人数和每个人签到的具体时间点
            $signRobotUidCacheKey = 'signRobotUidCacheKey';
            $uid = $redisObj->get($signRobotUidCacheKey);//先从redis获取到0点分配好的帐号id
            $count = count($uid);
            //根据每个时间段不同的比例分配人数总数
            switch($t){
                case 0:
                    $count = intval($count * 2/100);
                    $sign_time_arr = array(time(),time()+6*3600);
                    break;
                case 6:
                    $count = intval($count * 10/100);
                    $sign_time_arr = array(time(),time()+6*3600);
                    break;
                case 12:
                    $count = intval($count * 15/100);
                    $sign_time_arr = array(time(),time()+6*3600);
                    break;
                case 18:
                    $count = intval($count * 23/100);
                    $sign_time_arr = array(time(),time()+6*3600);
                    break;
                default:
                    $count = intval($count * 2/100);
                    $sign_time_arr = array(time(),time()+6*3600);
                    break;
            }
            //该时间段根据上面获得的人数，随机抽出具体帐号id，组成新的帐号id数组
            $key = array_rand($uid,$count);
            $uids=array();
            foreach($key as $k=>$v){
                $uids[$k] = $uid[$v];
            }
            $signRobotCacheKey = 'signRobotCacheKey';
            $time_arr=array();
            //循环为各个帐号id分配具体的随机签到时间
            foreach($uids as $k=>$v){
                $sign_time = mt_rand($sign_time_arr[0],$sign_time_arr[1]);
                $sign_time = strtotime(date('YmdHi',$sign_time));//把随机时间换成分钟
                while(in_array($sign_time,$time_arr)){//如果时间于其他帐号冲突则去重
                    $sign_time = mt_rand($sign_time_arr[0],$sign_time_arr[1]);
                    $sign_time = strtotime(date('YmdHi',$sign_time));
                }
                $time_arr[]=$sign_time;
                $data=array();
                $data['uid'] = $v;
                $data['sign_time'] = $sign_time;
                //将该时间段的签到帐号和时间信息存到redis哈希表
                $redisObj->hSet($signRobotCacheKey,$sign_time,json_encode($data));
            }
            echo 'ok';exit;
        }else{
            echo 'robot_close';exit;
        }
    }
### 2、编写php接口实现根据当前时间获取redis的数据，入库签到；
    /*
     * 匹配时间，签到入库
     */
    public function robotSignIn(){
        //查询签到机器人是否开启
        $robotObj = M('robot.robot_main',null,'robot');
        $find = $robotObj->where(array('id'=>'1'))->find();
        if($find['robot_status']==1){
            //实例化redis
            $redisObj = new redis();
            $redisObj->connect('127.0.0.1', 6379);
            //获取当前时间(分钟)
            $t=strtotime(date('YmdHi',time()));
            //获取该时间内redis的数据
            $signRobotCacheKey = 'signRobotCacheKey';
            $re = $redisObj->hGet($signRobotCacheKey,$t);
            if($re){
                $re = json_decode($re,true);
                //签到
                $signObj = M('user.user_sign',null,'user');
                //判断该用户是否第一次签到
                $find = $signObj->where(array('uid'=>$re['uid']))->find();
                if($find){
                    //不是第一次签到
                    $data=array();
                    $data['last_sign_time'] = $re['sign_time'];
                    $data['sign_count'] = $find['sign_count']+1;
                    $re = $signObj->where(array('uid'=>$re['uid']))->save($data);
                }else{
                    //第一次签到
                    $data=array();
                    $data['uid'] = $re['uid'];
                    $data['last_sign_time'] = $re['sign_time'];
                    $data['sign_count'] = 1;
                    $re = $signObj->add($data);
                }
                if($re && $re['status']){
                    $redisObj->hDel($signRobotCacheKey,$t);//成功则删除redis数据
                    echo 'ok';exit;
                }
            }else{
                echo 'no_data';exit;
            }
        }else{
            echo 'robot_close';exit;
        }
    }
### 3、编写2个perl脚本,分别对应以上两个php接口
    #!/usr/bin/perl
    use strict;
    use warnings;
    use LWP::Simple qw(get);
    #perl调用php方法SignRobot/robotSignIn
    my $SignRobotUrl = "http://localhost/www/index.php?m=SignRobot&a=robotSignIn";
    my $re= get($SignRobotUrl);
    print $re;
### 4、编写2个sh脚本,分别对应以上两个perl脚本
     #!/bin/bash
     #.sh文件为Linux脚本文件，设置Linux计划任务自动调用perl文件
     perl /data/www/perl/task/SignRobotIn.pl   

##设置Linux计划任务
**1、计划任务：**
是任务在约定的时间执行已经计划好的工作。在Linux中，我们经常用到cron 服务器来完成这项工作。比如我们可以在配置文件中约定每天早上4点，对httpd服务器重新启动，这就是一个计划任务；

**2、Cron是Linux的内置服务，可以用以下的方法启动、关闭这个服务：**

    /sbin/service crond start //启动服务
    /sbin/service crond stop //关闭服务
    /sbin/service crond restart //重启服务
    /sbin/service crond reload //重新载入配置

**3、任务调度设置文件的写法：**

编辑/etc/cron.d/sysstat

    vi /etc/cron.d/sysstat

具体格式如下：

       Minute  Hour Day  Month Dayofweek   command
       分钟    小时  天   月     天每星期       命令
每个字段代表的含义如下：

     Minute             每个小时的第几分钟执行该任务
     Hour               每天的第几个小时执行该任务
     Day                每月的第几天执行该任务
     Month              每年的第几个月执行该任务
     DayOfWeek          每周的第几天执行该任务
     Command            指定要执行的程序
     在这些字段里，除了“Command”是每次都必须指定的字段以外，其它字段皆为可选字段，可视需要决定。对于不指定的字段，要用“*”来填补其位置。
举例如下：

    5 * * * * root sh文件路径 >/dev/null 2 >&1  指定每小时的第5分钟执行一次ls命令
    30 5 * * *root sh文件路径 >/dev/null 2 >&1  指定每天的 5:30 执行ls命令
    30 7 8 * *root sh文件路径 >/dev/null 2 >&1  指定每月8号的7：30分执行ls命令
    30 5 8 6 *root sh文件路径 >/dev/null 2 >&1  指定每年的6月8日5：30执行ls命令
    30 6 * * 0 root sh文件路径 >/dev/null 2 >&1  指定每星期日的6:30执行ls命令[注：0表示星期天，1表示星期1，以此类推，也可以用英文来表示，sun表示星期天，mon表示星期一等]
    30 3 10,20 * * root sh文件路径 >/dev/null 2 >&1 每月10号及20号的3：30执行ls命令[注：“，”用来连接多个不连续的时段]
    25 8-11 * * * root sh文件路径 >/dev/null 2 >&1  每天8-11点的第25分钟执行ls命令[注：“-”用来连接连续的时段]
    */15 * * * * root sh文件路径 >/dev/null 2 >&1   每15分钟执行一次ls命令 [即每个小时的第0 15 30 45 60分钟执行ls命令 ]
    30 6 */10 * * root sh文件路径 >/dev/null 2 >&1  每个月中，每隔10天6:30执行一次ls命令[即每月的1、11、21、31日是的6：30执行一次ls命令。 ]

**4、编辑完成后，重启crond即可。**

    /sbin/service crond reload
**5、查看cron日志、看看计划任务是否成功执行**

    vi /var/log/cron
    
**完成以上步骤后，签到机器人就能正常运作了，其他例如评论机器人、自动回复机器人等模拟真实用户行为的功能原理也大致相同**

