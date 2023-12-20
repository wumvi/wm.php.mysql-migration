### Create migration user
```mysql
create database db123;
CREATE USER 'migration_user'@'%' IDENTIFIED WITH mysql_native_password BY 'pwd123';
GRANT ALL PRIVILEGES ON db123.* TO 'migration_user'@'%';
FLUSH PRIVILEGES;
```

### Create migration table
```mysql
create table db123.migration_log
(
    id       int unsigned auto_increment,
    `index`  int unsigned                         not null,
    filename varchar(255)                         not null,
    date_add datetime default current_timestamp() not null,
    constraint migration_log_pk
        primary key (id)
);
```

### Test
```bash
docker run -ti --rm -v "$(pwd)":/data/ --workdir /data/ --network host dfuhbu/php8.3-cli-dev:1 bash
```