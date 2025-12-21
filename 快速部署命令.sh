#!/bin/bash

# SendWalk 快速部署命令参考
# 部署路径: /data/www/sendwalk
# 域名: edm.sendwalk.com / api.sendwalk.com

cat << 'EOF'
====================================== 
  SendWalk 快速部署命令参考
  部署路径: /data/www/sendwalk
  域名: edm.sendwalk.com
======================================

【0. DNS 配置 - 在域名服务商操作】

添加以下 A 记录（假设服务器IP: 1.2.3.4）:

类型   主机记录    记录值
A      edm        1.2.3.4
A      api        1.2.3.4

验证 DNS 生效:
ping edm.sendwalk.com
ping api.sendwalk.com


【1. 创建项目目录】

sudo mkdir -p /data/www
sudo chown -R www-data:www-data /data/www


【2. 克隆代码】

cd /data/www
git clone https://github.com/your-username/sendwalk.git
sudo chown -R www-data:www-data /data/www/sendwalk


【3. 后端配置】

cd /data/www/sendwalk/backend
composer install --optimize-autoloader --no-dev
cp .env.example .env
# 编辑 .env 配置数据库等信息
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache


【4. 前端配置】

cd /data/www/sendwalk/frontend
npm install
cp .env.example .env
# 编辑 .env 设置 VITE_API_URL=https://api.sendwalk.com
npm run build


【5. Nginx 配置】

sudo cp /data/www/sendwalk/nginx/api.conf /etc/nginx/conf.d/sendwalk-api.conf
sudo cp /data/www/sendwalk/nginx/frontend.conf /etc/nginx/conf.d/sendwalk-frontend.conf
sudo nginx -t
sudo systemctl restart nginx


【6. Supervisor 配置】

sudo cp /data/www/sendwalk/supervisor/scheduler.conf /etc/supervisor/conf.d/sendwalk-scheduler.conf
sudo cp /data/www/sendwalk/supervisor/worker.conf /etc/supervisor/conf.d/sendwalk-worker-manager.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
sudo supervisorctl status


【7. SSL 配置】

# ✅ 已配置使用 Cloudflare 证书
# 证书文件: /data/www/ca/sendwalk.pem
# 私钥文件: /data/www/ca/sendwalk.key

# 确认证书文件存在
ls -lh /data/www/ca/sendwalk.pem
ls -lh /data/www/ca/sendwalk.key

# 设置正确的权限
sudo chown root:root /data/www/ca/sendwalk.pem
sudo chown root:root /data/www/ca/sendwalk.key
sudo chmod 644 /data/www/ca/sendwalk.pem
sudo chmod 600 /data/www/ca/sendwalk.key

# 验证证书
openssl x509 -in /data/www/ca/sendwalk.pem -text -noout
openssl x509 -in /data/www/ca/sendwalk.pem -noout -dates

# Nginx 配置已包含 SSL 设置，重启即可
sudo systemctl restart nginx

# 详细说明请查看: cat SSL证书配置说明.md


【8. 验证部署】

cd /data/www/sendwalk
./check-deployment.sh


【日常维护命令】

# 更新代码
cd /data/www/sendwalk && ./deploy.sh production

# 检查状态
cd /data/www/sendwalk && ./check-deployment.sh

# 查看日志
tail -f /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log
tail -f /data/www/sendwalk/backend/storage/logs/scheduler.log
tail -f /data/www/sendwalk/backend/storage/logs/manager.log

# 重启服务
sudo systemctl restart php8.3-fpm
sudo systemctl restart nginx
sudo supervisorctl restart all

# 清除缓存
cd /data/www/sendwalk/backend
php artisan config:clear
php artisan cache:clear
php artisan view:clear

# 重建缓存
cd /data/www/sendwalk/backend
php artisan config:cache
php artisan route:cache
php artisan view:cache


【故障排查】

# 检查服务状态
sudo systemctl status nginx
sudo systemctl status php8.3-fpm
sudo systemctl status mysql
sudo systemctl status redis-server
sudo supervisorctl status

# 查看错误日志
tail -50 /data/www/sendwalk/backend/storage/logs/laravel-$(date +%Y-%m-%d).log
tail -50 /var/log/nginx/sendwalk-api-error.log
tail -50 /var/log/nginx/sendwalk-frontend-error.log

# 测试 API
curl https://api.sendwalk.com/api/health
curl http://api.sendwalk.com/api/health


【权限问题】

# 重置权限
sudo chown -R www-data:www-data /data/www/sendwalk
sudo chmod -R 775 /data/www/sendwalk/backend/storage
sudo chmod -R 775 /data/www/sendwalk/backend/bootstrap/cache


【数据库】

# 备份数据库
mysqldump -u sendwalk -p sendwalk | gzip > /data/www/backup_$(date +%Y%m%d).sql.gz

# 恢复数据库
gunzip < backup_20250101.sql.gz | mysql -u sendwalk -p sendwalk


======================================
  快速参考完成
======================================

详细文档:
- 域名配置说明.md (域名配置详细步骤)
- 部署路径说明.md (部署路径说明)
- 生产环境部署指南.md (完整部署指南)

======================================
EOF

