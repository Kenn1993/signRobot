#!/usr/bin/perl
use strict;
use warnings;
use LWP::Simple qw(get);
#perl调用php方法SignRobot/robotSignIn
my $SignRobotUrl = "http://localhost/www/index.php?m=SignRobot&a=robotSignIn";
my $re= get($SignRobotUrl);
print $re;