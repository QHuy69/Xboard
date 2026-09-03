# Cloudflare-only origin gate

`cloudflare-realip.conf` is an HTTP-context Nginx include. It both resolves
`CF-Connecting-IP` only from Cloudflare networks and exposes
`$cloudflare_origin_allowed`, which is based on the original peer address.

Include it from `/etc/nginx/conf.d/` and add this safe `return` guard to every
public site `server` block (HTTP and HTTPS):

```nginx
if ($cloudflare_origin_allowed = 0) { return 403; }
```

Do not use `allow/deny` after enabling `real_ip_header`: Nginx will then see
the rewritten visitor address and reject legitimate Cloudflare traffic.
