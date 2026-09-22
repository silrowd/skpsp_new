import http.server
import os

ROOT = os.path.dirname(os.path.abspath(__file__))

class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=ROOT, **kwargs)

    def send_error(self, code, message=None, explain=None):
        if code == 404:
            path = self.path.split(chr(63), 1)[0].split(chr(35), 1)[0]
            if not path.endswith("html"):
                cand = path + ".html"
                if os.path.isfile(os.path.join(ROOT, cand.lstrip("/").replace("/", os.sep))):
                    self.path = cand
                    return super().do_GET()
            self.path = "/404.html"
            self.send_response(404)
            p = os.path.join(ROOT, "404.html")
            if os.path.isfile(p):
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.send_header("Content-Length", str(os.path.getsize(p)))
                self.end_headers()
                self.wfile.write(open(p, "rb").read())
                return
        return super().send_error(code, message, explain)

BaseHandler = Handler

if __name__ == "__main__":
    http.server.ThreadingHTTPServer(("0.0.0.0", 8001), Handler).serve_forever()
