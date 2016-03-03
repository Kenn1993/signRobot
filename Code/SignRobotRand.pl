#!/usr/bin/perl
use strict;
use warnings;
use LWP::Simple qw(get);
#perl调用php方法SignRobot/getRandUid
my $SignRobotUrl = "http://localhost/www/index.php?m=SignRobot&a=getRandUid";
my $re= get($SignRobotUrl);
print $re;

