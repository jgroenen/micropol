"""The OAuth flows of the auth service, and how the api uses its tokens: PKCE, codes that work once,
refresh with rotation (and ending the login when an old refresh token is used again), introspection,
revocation, and the checks on clients and redirect uris. Run it with tests/run.sh.
"""
import base64, hashlib, json, os, secrets, sys, urllib.parse, urllib.request, urllib.error
A, API = 'http://localhost:8005', 'http://localhost:8001'
fouten = 0
class GeenRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(GeenRedirect)
def http(methode, url, data=None, headers={}):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, method=methode, headers=headers)
    try:
        r = opener.open(req); return r.status, dict(r.headers), r.read().decode()
    except urllib.error.HTTPError as e: return e.code, dict(e.headers), e.read().decode()
def check(naam, voorwaarde, info=''):
    global fouten; fouten += not voorwaarde; print(('ok  ' if voorwaarde else 'FOUT'), naam, info if not voorwaarde else '')
def pkce():
    v = base64.urlsafe_b64encode(secrets.token_bytes(32)).rstrip(b'=').decode()
    return v, base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b'=').decode()
basic = {'Authorization': 'Basic ' + base64.b64encode(f'minipol-api:{os.environ.get("MINIPOL_API_SECRET", "dev-api-secret")}'.encode()).decode()}
RU = 'http://localhost:8002/'

st, h, t = http('GET', A + '/.well-known/openid-configuration'); d = json.loads(t)
check('discovery', st == 200 and d['token_endpoint'] == A + '/token' and d['code_challenge_methods_supported'] == ['S256'])

GEBRUIKER = os.environ.get('TEST_GEBRUIKER', 'minipol-test')
WACHTWOORD = os.environ.get('TEST_WACHTWOORD', 'testwachtwoord123')
API_GEHEIM = os.environ.get('MINIPOL_API_SECRET', 'dev-api-secret')

def login(user=GEBRUIKER, pw=WACHTWOORD):
    v, c = pkce(); state = secrets.token_hex(8)
    q = {'response_type': 'code', 'client_id': 'minipol-admin', 'redirect_uri': RU, 'state': state, 'code_challenge': c, 'code_challenge_method': 'S256'}
    st, h, t = http('POST', A + '/authorize', {**q, 'gebruikersnaam': user, 'wachtwoord': pw})
    return st, h, t, v, state

v, c = pkce()
q = {'response_type': 'code', 'client_id': 'minipol-admin', 'redirect_uri': RU, 'state': 'x', 'code_challenge': c, 'code_challenge_method': 'S256'}
st, h, t = http('GET', A + '/authorize?' + urllib.parse.urlencode(q))
check('loginpagina', st == 200 and 'name="wachtwoord"' in t and h.get('X-Frame-Options') == 'DENY')
st, h, t = http('GET', A + '/authorize?' + urllib.parse.urlencode({**q, 'redirect_uri': 'http://evil.example/'}))
check('onbekend terugkeeradres: foutpagina, geen redirect', st == 400 and 'Location' not in h)
st, h, t = http('GET', A + '/authorize?' + urllib.parse.urlencode({**q, 'client_id': 'onbekend'}))
check('onbekende client: foutpagina', st == 400 and 'Location' not in h)
st, h, t = http('GET', A + '/authorize?' + urllib.parse.urlencode({k: v for k, v in q.items() if k != 'code_challenge'}))
check('zonder PKCE: terug met invalid_request', st == 302 and 'error=invalid_request' in h['Location'] and 'state=x' in h['Location'])
st, h, t = http('GET', A + '/authorize?' + urllib.parse.urlencode({**q, 'code_challenge_method': 'plain'}))
check('PKCE plain geweigerd', st == 302 and 'error=invalid_request' in h['Location'])

st, h, t, v, state = login(pw='fout')
check('fout wachtwoord: formulier opnieuw', st == 401 and 'Onjuiste gebruikersnaam' in t and 'Location' not in h)
st, h, t, v, state = login()
loc = urllib.parse.urlparse(h.get('Location', '')); p = urllib.parse.parse_qs(loc.query)
check('login: terug naar admin met code en state', st == 302 and h['Location'].startswith(RU) and p.get('state') == [state] and 'code' in p)
code = p['code'][0]

st, h, t = http('POST', A + '/token', {'grant_type': 'authorization_code', 'code': code, 'redirect_uri': RU, 'client_id': 'minipol-admin', 'code_verifier': 'fout' * 11})
check('verkeerde code_verifier geweigerd', st == 400 and json.loads(t)['error'] == 'invalid_grant')
st, h, t = http('POST', A + '/token', {'grant_type': 'authorization_code', 'code': code, 'redirect_uri': RU, 'client_id': 'minipol-admin', 'code_verifier': v})
check('code is na één poging (ook fout) niet meer bruikbaar', st == 400 and json.loads(t)['error'] == 'invalid_grant')

st, h, t, v, state = login(); code = urllib.parse.parse_qs(urllib.parse.urlparse(h['Location']).query)['code'][0]
st, h, t = http('POST', A + '/token', {'grant_type': 'authorization_code', 'code': code, 'redirect_uri': RU, 'client_id': 'minipol-admin', 'code_verifier': v})
tok = json.loads(t)
check('code inwisselen', st == 200 and len(tok['access_token']) == 64 and tok['expires_in'] > 800 and h.get('Cache-Control') == 'no-store', t)

st, h, t = http('POST', A + '/introspect', {'token': tok['access_token']})
check('introspect zonder inlog: 401', st == 401)
st, h, t = http('POST', A + '/introspect', {'token': tok['access_token']}, {'Authorization': 'Basic ' + base64.b64encode(b'minipol-api:fout').decode()})
check('introspect met fout geheim: 401', st == 401)
st, h, t = http('POST', A + '/introspect', {'token': tok['access_token']}, basic); i = json.loads(t)
check('introspect access token: actief', st == 200 and i['active'] and i['username'] == GEBRUIKER, t)
st, h, t = http('POST', A + '/introspect', {'token': tok['refresh_token']}, basic)
check('refresh token is geen bearer: niet actief', json.loads(t) == {'active': False})
st, h, t = http('GET', A + '/userinfo', headers={'Authorization': 'Bearer ' + tok['access_token']})
check('userinfo', st == 200 and json.loads(t)['preferred_username'] == GEBRUIKER, t)

# with a valid token the api gets past the login check: an unknown gesprek then gives 404, not 401
st, h, t = http('GET', API + '/beoordelingen?gesprek_id=bestaatniet', headers={'Authorization': 'Bearer ' + tok['access_token']})
check('API met access token: toegelaten', st == 404, t[:100])
st, h, t = http('GET', API + '/beoordelingen?gesprek_id=bestaatniet')
check('API zonder token: 401', st == 401)
st, h, t = http('GET', API + '/beoordelingen?gesprek_id=bestaatniet', headers={'Authorization': 'Bearer ' + 'a' * 64})
check('API met onbekend token: 401', st == 401)

st, h, t = http('POST', A + '/token', {'grant_type': 'refresh_token', 'refresh_token': tok['refresh_token'], 'client_id': 'minipol-admin'})
nieuw = json.loads(t)
check('refresh: nieuw paar', st == 200 and nieuw['refresh_token'] != tok['refresh_token'] and nieuw['access_token'] != tok['access_token'])
st, h, t = http('POST', A + '/token', {'grant_type': 'refresh_token', 'refresh_token': tok['refresh_token'], 'client_id': 'minipol-admin'})
check('oud refresh token opnieuw: geweigerd', st == 400 and 'already used' in json.loads(t)['error_description'])
st, h, t = http('POST', A + '/introspect', {'token': nieuw['access_token']}, basic)
check('...en daardoor is de hele login beëindigd', json.loads(t) == {'active': False})
st, h, t = http('POST', A + '/token', {'grant_type': 'refresh_token', 'refresh_token': nieuw['refresh_token'], 'client_id': 'minipol-admin'})
check('...ook het nieuwe refresh token werkt niet meer', st == 400)

st, h, t, v, state = login(); code = urllib.parse.parse_qs(urllib.parse.urlparse(h['Location']).query)['code'][0]
tok = json.loads(http('POST', A + '/token', {'grant_type': 'authorization_code', 'code': code, 'redirect_uri': RU, 'client_id': 'minipol-admin', 'code_verifier': v})[2])
st, h, t = http('POST', A + '/revoke', {'token': tok['refresh_token'], 'client_id': 'minipol-admin'})
check('uitloggen (revoke)', st == 200)
st, h, t = http('POST', A + '/introspect', {'token': tok['access_token']}, basic)
check('...access token direct niet meer actief', json.loads(t) == {'active': False})
st, h, t = http('POST', A + '/revoke', {'token': 'onbekend', 'client_id': 'minipol-admin'})
check('revoke onbekend token: ook 200', st == 200)
st, h, t = http('POST', A + '/token', {'grant_type': 'password', 'client_id': 'minipol-admin'})
check('andere grant_type geweigerd', st == 400 and json.loads(t)['error'] == 'unsupported_grant_type')
st, h, t = http('POST', A + '/token', {'grant_type': 'refresh_token', 'client_id': 'onbekend'})
check('onbekende client bij /token: 401', st == 401 and json.loads(t)['error'] == 'invalid_client')
st, h, t = http('POST', A + '/token', {'grant_type': 'refresh_token', 'refresh_token': 'x', 'client_id': 'minipol-admin'}, {'Origin': 'http://localhost:8002'})
check('CORS voor de admin', h.get('Access-Control-Allow-Origin') == 'http://localhost:8002')
print(f'oauth.py: {fouten} fouten')
sys.exit(1 if fouten else 0)
