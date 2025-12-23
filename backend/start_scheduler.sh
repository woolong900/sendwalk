#!/bin/bash
cd /Users/panlei/sendwalk/backend
nohup php artisan schedule:work > /Users/panlei/sendwalk/backend/storage/logs/scheduler.log 2>&1 &
echo $!
