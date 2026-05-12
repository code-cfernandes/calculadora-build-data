FROM nginx:1.27-alpine

# O entrypoint oficial do nginx processa *.conf.template em /etc/nginx/templates/
# substituindo variáveis de ambiente com envsubst antes de iniciar.
COPY nginx.conf.template /etc/nginx/templates/default.conf.template
COPY index.html          /usr/share/nginx/html/index.html
COPY login.html          /usr/share/nginx/html/login.html
COPY robots.txt          /usr/share/nginx/html/robots.txt

ENV NGINX_PORT=80
EXPOSE ${NGINX_PORT}
