import http.server
import os

ROOT = os.path.dirname(os.path.abspath(__file__))

class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=ROOT, **kwargs)

    def send_error(self, code, message=None, explain=None):
        if code == 404:
            path = self.path.split('?', 1)[0].split('#', 1)[0]
            if not path.endswith('.html'):
                candidate = path + '.html'
                if os.path.isfile(os.path.join(ROOT, candidate.lstrip('/').replace('/', os.sep))):
                    self.path = candidate
                    return super().do_GET()
            # custom 404 page (dark theme)
            self.path = '/404.html'
            self.send_response(404)
            path = os.path.join(ROOT, '404.html')
            if os.path.isfile(path):
                self.send_header('Content-Type', 'text/html; charset=utf-8')
                self.send_header('Content-Length', str(os.path.getsize(path)))
                self.end_headers()
                with open(path, 'rb') as f:
                    self.wfile.write(f.read())
                return
        return super().send_error(code, message, explain)

if __name__ == '__main__':
    http.server.ThreadingHTTPServer(('127.0.0.1', 8000), Handler).serve_forever()
