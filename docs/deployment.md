# SendWalk 部署指南

## 系统要求

### 服务器要求

- **操作系统**: Ubuntu 20.04+ / CentOS 8+ / macOS
- **CPU**: 2核以上
- **内存**: 4GB以上
- **硬盘**: 20GB以上

### 软件要求

- PHP 8.3+
- Node.js 18+
- MySQL 8.0+
- Redis 7+
- Nginx 或 Apache
- Composer
- Supervisor (用于队列管理)

## 本地开发环境部署

### 1. 克隆项目

```bash
git clone https://github.com/your-repo/sendwalk.git
cd sendwalk
```

### 2. 后端设置

```bash
cd backend

# 安装依赖
composer install

# 复制环境配置
cp .env.example .env

# 生成应用密钥
php artisan key:generate

# 配置数据库
# 编辑 .env 文件，设置数据库连接信息
nano .env

# 运行迁移
php artisan migrate --seed

# 启动开发服务器
php artisan serve
```

### 3. 前端设置

```bash
cd frontend

# 安装依赖
npm install

# 复制环境配置
cp .env.example .env

# 启动开发服务器
npm run dev
```

### 4. 启动队列处理器

```bash
cd backend
php artisan queue:work
# 或使用 Horizon
php artisan horizon
```

### 5. 启动定时任务

```bash
# 在 crontab 中添加
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

## Docker 部署

### 使用 Docker Compose

```bash
# 启动所有服务
docker-compose up -d

# 安装后端依赖
docker-compose exec backend composer install

# 运行迁移
docker-compose exec backend php artisan migrate --seed

# 查看日志
docker-compose logs -f
```

### 访问应用

- 前端: http://localhost:5173
- 后端 API: http://localhost:8000
- Horizon: http://localhost:8000/horizon
- Mailhog: http://localhost:8025

## 生产环境部署

### 1. 服务器准备

#### 安装 PHP 8.3

```bash
# Ubuntu
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.3 php8.3-fpm php8.3-mysql php8.3-redis \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
    php8.3-gd php8.3-bcmath
```

#### 安装 MySQL

```bash
sudo apt install mysql-server
sudo mysql_secure_installation
```

#### 安装 Redis

```bash
sudo apt install redis-server
sudo systemctl enable redis-server
```

#### 安装 Nginx

```bash
sudo apt install nginx
sudo systemctl enable nginx
```

#### 安装 Node.js

```bash
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install nodejs
```

#### 安装 Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2. 部署应用

```bash
# 克隆代码
cd /var/www
git clone https://github.com/your-repo/sendwalk.git
cd sendwalk

# 设置权限
sudo chown -R www-data:www-data /var/www/sendwalk
sudo chmod -R 755 /var/www/sendwalk
```

#### 后端部署

```bash
cd /var/www/sendwalk/backend

# 安装依赖
composer install --optimize-autoloader --no-dev

# 配置环境
cp .env.example .env
nano .env  # 编辑配置

# 生成密钥
php artisan key:generate

# 运行迁移
php artisan migrate --force

# 优化
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 设置权限
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

#### 前端部署

```bash
cd /var/www/sendwalk/frontend

# 安装依赖
npm install

# 构建生产版本
npm run build

# 部署构建文件到 Nginx
sudo cp -r dist/* /var/www/html/sendwalk/
```

### 3. Nginx 配置

创建后端配置文件：

```bash
sudo nano /etc/nginx/sites-available/sendwalk-api
```

```nginx
server {
    listen 80;
    server_name api.sendwalk.com;
    root /var/www/sendwalk/backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

创建前端配置文件：

```bash
sudo nano /etc/nginx/sites-available/sendwalk-web
```

```nginx
server {
    listen 80;
    server_name sendwalk.com www.sendwalk.com;
    root /var/www/html/sendwalk;

    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api {
        proxy_pass http://api.sendwalk.com;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

启用配置：

```bash
sudo ln -s /etc/nginx/sites-available/sendwalk-api /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/sendwalk-web /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 4. SSL 配置 (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d sendwalk.com -d www.sendwalk.com
sudo certbot --nginx -d api.sendwalk.com
```

### 5. Supervisor 配置 (队列管理)

创建配置文件：

```bash
sudo nano /etc/supervisor/conf.d/sendwalk-worker.conf
```

```ini
[program:sendwalk-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/sendwalk/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/sendwalk/backend/storage/logs/worker.log
stopwaitsecs=3600
```

创建 Horizon 配置：

```bash
sudo nano /etc/supervisor/conf.d/sendwalk-horizon.conf
```

```ini
[program:sendwalk-horizon]
process_name=%(program_name)s
command=php /var/www/sendwalk/backend/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/sendwalk/backend/storage/logs/horizon.log
stopwaitsecs=3600
```

启动 Supervisor：

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

### 6. 定时任务配置

```bash
sudo crontab -e -u www-data
```

添加：

```
* * * * * cd /var/www/sendwalk/backend && php artisan schedule:run >> /dev/null 2>&1
```

## 环境变量配置

### 后端 (.env)

```env
APP_NAME=SendWalk
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://api.sendwalk.com

FRONTEND_URL=https://sendwalk.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sendwalk
DB_USERNAME=sendwalk_user
DB_PASSWORD=strong_password

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis

# 邮件配置
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@sendwalk.com
MAIL_FROM_NAME="${APP_NAME}"

# AWS SES (可选)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1

# SendGrid (可选)
SENDGRID_API_KEY=

# Mailgun (可选)
MAILGUN_DOMAIN=
MAILGUN_SECRET=
```

### 前端 (.env)

```env
VITE_API_URL=https://api.sendwalk.com/api
```

## 备份策略

### 数据库备份

```bash
# 创建备份脚本
sudo nano /usr/local/bin/backup-sendwalk.sh
```

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/sendwalk"
mkdir -p $BACKUP_DIR

# 备份数据库
mysqldump -u sendwalk_user -p'password' sendwalk > $BACKUP_DIR/db_$DATE.sql

# 压缩
gzip $BACKUP_DIR/db_$DATE.sql

# 删除7天前的备份
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +7 -delete
```

```bash
sudo chmod +x /usr/local/bin/backup-sendwalk.sh
```

添加定时任务：

```bash
sudo crontab -e
```

```
0 2 * * * /usr/local/bin/backup-sendwalk.sh
```

## 监控和日志

### 应用日志

- Laravel 日志: `/var/www/sendwalk/backend/storage/logs/laravel.log`
- Nginx 日志: `/var/log/nginx/`
- PHP-FPM 日志: `/var/log/php8.3-fpm.log`

### 监控 Horizon

访问: `https://api.sendwalk.com/horizon`

### 查看队列状态

```bash
cd /var/www/sendwalk/backend
php artisan queue:work --once
php artisan horizon:status
```

## 故障排除

### 权限问题

```bash
sudo chown -R www-data:www-data /var/www/sendwalk/backend/storage
sudo chmod -R 775 /var/www/sendwalk/backend/storage
```

### 清除缓存

```bash
cd /var/www/sendwalk/backend
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### 重启服务

```bash
sudo systemctl restart nginx
sudo systemctl restart php8.3-fpm
sudo systemctl restart redis
sudo supervisorctl restart all
```

## 性能优化

### PHP-FPM 优化

编辑 `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 500
```

### MySQL 优化

编辑 `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
max_connections = 200
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
query_cache_size = 0
query_cache_type = 0
```

### Redis 优化

编辑 `/etc/redis/redis.conf`:

```ini
maxmemory 512mb
maxmemory-policy allkeys-lru
```

## 安全建议

1. 定期更新系统和软件包
2. 使用强密码
3. 启用防火墙
4. 配置 fail2ban
5. 定期备份数据
6. 监控服务器资源
7. 使用 HTTPS
8. 限制数据库访问

## 扩展性

### 水平扩展

- 使用负载均衡器（Nginx、HAProxy）
- 分离数据库服务器
- 使用 Redis 集群
- 使用 CDN 加速静态资源

### 队列扩展

增加 worker 数量：

```bash
sudo nano /etc/supervisor/conf.d/sendwalk-worker.conf
# 修改 numprocs=8
sudo supervisorctl reread
sudo supervisorctl update
```

