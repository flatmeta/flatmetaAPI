# FlatMeta.io Backend

## Prepare Environment
To get started with This Project, requirements are as follow:

### Prerequisites:

- [Install and Setup Xampp (Apache and MySQL)](https://www.apachefriends.org/)

- [Install and composer](https://getcomposer.org/Composer-Setup.exe)


### Download and Clone: 

```
$ git clone https://github.com/flatmeta/flatmeta.io.git
```

### Add cloned folder into the xampp/htdocs folder: 

- Find .env.example
- Copy file with name .env 
- Open CMD (Command prompt) and use below command  

```
Composer install
```

```
Composer Update
```

### Run Mysql and apache:

- Open xampp folder
- Find and open xampp-control.exe
- Just start Apache and MySQL 

### After successfully start Apache and MySQL:

- Open localhost/phpmyadmin (http://127.0.0.1/phpmyadmin)
- Create DB with same name added on env.
- Just start Apache and MySQL 

### Run Migration command with seed: 

- Open CMD (Command prompt) and use below command  

```
php artisan migrate:fresh --seed
```

### Run Project:

```
php artisan serve
```

## Contributing

Thanks for your interest in contributing!
