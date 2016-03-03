<?php
/**
 *签到机器人
 */
class SignRobotAction extends Action{
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
}