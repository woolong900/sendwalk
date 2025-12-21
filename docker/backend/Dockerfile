FROM php:8.3-fpm

# 设置工作目录
WORKDIR /var/www

# 安装系统依赖
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    supervisor

# 清理缓存
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 安装 PHP 扩展
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# 安装 Redis 扩展
RUN pecl install redis && docker-php-ext-enable redis

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 创建系统用户
RUN groupadd -g 1000 www
RUN useradd -u 1000 -ms /bin/bash -g www www

# 复制现有应用
COPY --chown=www:www . /var/www

# 切换到非root用户
USER www

# 暴露端口
EXPOSE 9000

CMD ["php-fpm"]

