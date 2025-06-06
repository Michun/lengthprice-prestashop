0. what you need is -> composer and docker installed in your sys
1. Copy docker-compose.yml and Dockerfile to your dev folder i.e. ~/Projects/prestashop-dev
2. run `docker compose up -d`
3. check all containers logs if there are no problems with startup
4. run 'docker compose exec prestashop bash'
5. rename /admin to /adminXYZ (XYZ is random string)
6. go into localhost:8080/adminXYZ
7. if everything works well, then clone the repository into modules catalogue ~/Projects/prestashop-dev/modules
8. in module manager panel you should see lengthprice module (last position in others)
9. happy developing