"""Every call of the api, math server and auth service, checked against their OpenAPI spec: the status must
be the expected one and in the spec, and the request and response must fit the schema. Every operation in
the specs must be called at least once. The test makes its own data (gesprekken, stellingen, antwoorden)
through the api; run it with tests/run.sh, which puts the data back afterwards.
"""
import base64, hashlib, json, os, re, secrets, sys, urllib.error, urllib.parse, urllib.request
from jsonschema import Draft202012Validator

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPECS = {naam: json.load(open(f'{ROOT}/{naam}/openapi.json')) for naam in ('api', 'math', 'auth')}
BASIS = {'api': 'http://localhost:8001', 'math': 'http://localhost:8004', 'auth': 'http://localhost:8005'}
ADMIN = 'http://localhost:8002/'
GEBRUIKER = os.environ.get('TEST_GEBRUIKER', 'minipol-test')
WACHTWOORD = os.environ.get('TEST_WACHTWOORD', 'testwachtwoord123')
API_GEHEIM = os.environ.get('MINIPOL_API_SECRET', 'dev-api-secret')

fouten = 0
gedekt = set()


class GeenRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


urllib.request.install_opener(urllib.request.build_opener(GeenRedirect))


def verzoek(dienst, methode, pad, body=None, token=None, formulier=False, basic=None):
    headers = {'Content-Type': 'application/x-www-form-urlencoded' if formulier else 'application/json'}
    if token:
        headers['Authorization'] = f'Bearer {token}'
    if basic:
        headers['Authorization'] = 'Basic ' + base64.b64encode(basic.encode()).decode()
    data = None if body is None else (urllib.parse.urlencode(body) if formulier else json.dumps(body)).encode()
    request = urllib.request.Request(BASIS[dienst] + pad, data=data, method=methode, headers=headers)
    try:
        with urllib.request.urlopen(request) as response:
            return response.status, dict(response.headers), response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, dict(error.headers), error.read().decode()


def schemafouten(spec, schema, data, wat):
    wortel = dict(schema, components=spec['components'])
    return [f'{wat} {"/".join(map(str, e.absolute_path))}: {e.message[:120]}' for e in Draft202012Validator(wortel).iter_errors(data)]


def check(dienst, methode, pad, verwacht, body=None, token=None, formulier=False, basic=None):
    """one call, checked against the spec; returns the json of the response, or the headers for a redirect"""
    global fouten
    spec = SPECS[dienst]
    status, headers, tekst = verzoek(dienst, methode, pad, body, token, formulier, basic)
    kaal = pad.split('?')[0]
    sjabloon = next((p for p in spec['paths'] if re.fullmatch(re.sub(r'\{[^}]+\}', '[^/]+', p), kaal)), None)
    operatie = spec['paths'].get(sjabloon, {}).get(methode.lower()) if sjabloon else None
    meldingen = []
    if status != verwacht:
        meldingen.append(f'status {status}, verwacht {verwacht}: {tekst[:120]}')
    elif operatie is None:
        meldingen.append('niet in de spec')
    elif str(status) not in operatie['responses']:
        meldingen.append(f'status {status} niet in de spec')
    else:
        gedekt.add((dienst, methode, sjabloon))
        vraag = operatie.get('requestBody', {}).get('content', {}).get('application/json', {}).get('schema')
        if vraag and body is not None and not formulier and status < 300:
            meldingen += schemafouten(spec, vraag, body, 'request')
        antwoord = operatie['responses'][str(status)]
        while '$ref' in antwoord:
            antwoord = spec['components']['responses'][antwoord['$ref'].split('/')[-1]]
        schema = antwoord.get('content', {}).get('application/json', {}).get('schema')
        if schema:
            meldingen += schemafouten(spec, schema, json.loads(tekst), 'response')
        elif 'text/html' in antwoord.get('content', {}) and 'text/html' not in headers.get('Content-Type', ''):
            meldingen.append(f"content-type {headers.get('Content-Type')}")
    fouten += bool(meldingen)
    print(f"{'FOUT' if meldingen else 'ok  '} {dienst} {methode} {pad[:70]} {status} {'; '.join(meldingen[:3])}")
    if status in (301, 302):
        return headers
    return json.loads(tekst) if tekst and 'json' in headers.get('Content-Type', '') else None


def pkce():
    verifier = base64.urlsafe_b64encode(secrets.token_bytes(32)).rstrip(b'=').decode()
    return verifier, base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b'=').decode()


# ---- auth: logging in with the authorization code flow
check('auth', 'GET', '/.well-known/openid-configuration', 200)
check('auth', 'GET', '/.well-known/oauth-authorization-server', 200)
verifier, challenge = pkce()
q = {'response_type': 'code', 'client_id': 'minipol-admin', 'redirect_uri': ADMIN, 'state': 's',
     'code_challenge': challenge, 'code_challenge_method': 'S256'}
check('auth', 'GET', '/authorize?' + urllib.parse.urlencode(q), 200)
check('auth', 'GET', '/authorize?' + urllib.parse.urlencode({**q, 'code_challenge': ''}), 302)
check('auth', 'GET', '/authorize?' + urllib.parse.urlencode({**q, 'client_id': 'onbekend'}), 400)
check('auth', 'POST', '/authorize', 400, {'client_id': 'onbekend'}, formulier=True)
check('auth', 'POST', '/authorize', 401, {**q, 'gebruikersnaam': GEBRUIKER, 'wachtwoord': 'fout'}, formulier=True)
terug = check('auth', 'POST', '/authorize', 302, {**q, 'gebruikersnaam': GEBRUIKER, 'wachtwoord': WACHTWOORD}, formulier=True)
code = urllib.parse.parse_qs(urllib.parse.urlparse(terug['Location']).query)['code'][0]
wissel = {'grant_type': 'authorization_code', 'code': code, 'redirect_uri': ADMIN, 'client_id': 'minipol-admin', 'code_verifier': verifier}
tokens = check('auth', 'POST', '/token', 200, wissel, formulier=True)
check('auth', 'POST', '/token', 400, wissel, formulier=True)
check('auth', 'POST', '/token', 401, {'grant_type': 'refresh_token', 'client_id': 'onbekend'}, formulier=True)
tokens = check('auth', 'POST', '/token', 200, {'grant_type': 'refresh_token', 'refresh_token': tokens['refresh_token'], 'client_id': 'minipol-admin'}, formulier=True)
TOKEN = tokens['access_token']
check('auth', 'POST', '/introspect', 200, {'token': TOKEN}, formulier=True, basic=f'minipol-api:{API_GEHEIM}')
check('auth', 'POST', '/introspect', 200, {'token': 'onbekend'}, formulier=True, basic=f'minipol-api:{API_GEHEIM}')
check('auth', 'POST', '/introspect', 401, {'token': TOKEN}, formulier=True)
check('auth', 'GET', '/userinfo', 200, token=TOKEN)
check('auth', 'GET', '/userinfo', 401)
check('auth', 'POST', '/revoke', 401, {'token': 'x', 'client_id': 'onbekend'}, formulier=True)
check('auth', 'GET', '/docs', 200)
check('auth', 'GET', '/docs/openapi.json', 200)

# ---- api: gesprekken (the test makes its own)
check('api', 'POST', '/gesprekken', 401, {'titel': 'x'})
check('api', 'POST', '/gesprekken', 400, {'titel': ''}, token=TOKEN)
G = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek', 'omschrijving': 'Van tests/api.py.'}, token=TOKEN)['id']
VOORAF = check('api', 'POST', '/gesprekken', 201, {'titel': 'Testgesprek vooraf', 'moderatie': 'vooraf'}, token=TOKEN)['id']
check('api', 'PUT', f'/gesprekken/{G}', 200, {'titel': 'Testgesprek (aangepast)'}, token=TOKEN)
check('api', 'PUT', '/gesprekken/bestaatniet', 404, {'titel': 'x'}, token=TOKEN)
check('api', 'PUT', f'/gesprekken/{G}', 401, {'titel': 'x'})
check('api', 'GET', '/gesprekken', 200)
check('api', 'GET', '/gesprekken/bestaatniet', 404)

# ---- api: stellingen and antwoorden, from a few deelnemers
stellingen = [check('api', 'POST', '/stellingen', 201, {'gesprek_id': G, 'deelnemer_id': 'deelnemer-1', 'tekst': f'Teststelling {i}.'})['id'] for i in range(1, 9)]
check('api', 'POST', '/stellingen', 400, {'gesprek_id': G})
check('api', 'POST', '/stellingen', 404, {'gesprek_id': 'bestaatniet', 'deelnemer_id': 'x', 'tekst': 'x'})
wacht = check('api', 'POST', '/stellingen', 201, {'gesprek_id': VOORAF, 'deelnemer_id': 'deelnemer-1', 'tekst': 'Wacht op goedkeuring.'})
if wacht['zichtbaar']:
    print('FOUT een stelling in een gesprek met moderatie vooraf is meteen zichtbaar'); fouten += 1
check('api', 'GET', f'/stellingen?gesprek_id={G}&deelnemer_id=deelnemer-1', 200)
check('api', 'GET', f'/stellingen?gesprek_id={G}', 400)
check('api', 'GET', '/stellingen?gesprek_id=bestaatniet&deelnemer_id=x', 404)
check('api', 'GET', f'/gesprekken/{G}', 200)
for d in range(1, 5):
    for i, s in enumerate(stellingen):
        check('api', 'POST', '/antwoorden', 201, {'gesprek_id': G, 'deelnemer_id': f'deelnemer-{d}', 'stelling_id': s, 'waarde': ['eens', 'neutraal', 'oneens'][(i + d) % 3]})
check('api', 'POST', '/antwoorden', 400, {'gesprek_id': G, 'deelnemer_id': 'x', 'stelling_id': stellingen[0], 'waarde': 'misschien'})
check('api', 'POST', '/antwoorden', 404, {'gesprek_id': G, 'deelnemer_id': 'x', 'stelling_id': wacht['id'], 'waarde': 'eens'})
check('api', 'POST', '/antwoorden', 404, {'gesprek_id': 'bestaatniet', 'deelnemer_id': 'x', 'stelling_id': 'x', 'waarde': 'eens'})
check('api', 'GET', f'/antwoorden?gesprek_id={G}&deelnemer_id=deelnemer-1', 200)
check('api', 'GET', f'/antwoorden?gesprek_id={G}', 200)
check('api', 'GET', '/antwoorden', 400)
check('api', 'GET', '/antwoorden?gesprek_id=bestaatniet', 404)

# ---- api: moderatie
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 200, token=TOKEN)
check('api', 'GET', f'/beoordelingen?gesprek_id={G}', 401)
check('api', 'GET', '/beoordelingen', 400, token=TOKEN)
check('api', 'GET', '/beoordelingen?gesprek_id=bestaatniet', 404, token=TOKEN)
check('api', 'POST', '/beoordelingen', 200, {'gesprek_id': VOORAF, 'stelling_id': wacht['id'], 'beoordeling': 'goedgekeurd'}, token=TOKEN)
check('api', 'POST', '/beoordelingen', 200, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'afgekeurd', 'reden': 'Test'}, token=TOKEN)
check('api', 'POST', '/beoordelingen', 400, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'afgekeurd'}, token=TOKEN)
check('api', 'POST', '/beoordelingen', 401, {'gesprek_id': G, 'stelling_id': stellingen[-1], 'beoordeling': 'goedgekeurd'})
check('api', 'POST', '/beoordelingen', 404, {'gesprek_id': G, 'stelling_id': 'bestaatniet', 'beoordeling': 'goedgekeurd'}, token=TOKEN)

# ---- api: export and docs
export = check('api', 'GET', f'/export?gesprek_id={G}', 200)
if len(export['stellingen']) != len(stellingen) - 1:
    print('FOUT de afgekeurde stelling staat in de export'); fouten += 1
check('api', 'GET', '/export', 400)
check('api', 'GET', '/export?gesprek_id=bestaatniet', 404)
check('api', 'GET', '/docs', 200)
check('api', 'GET', '/docs/openapi.json', 200)

# ---- math: the analyse of the test gesprek
check('math', 'GET', f"/analyse?api={urllib.parse.quote(BASIS['api'])}&gesprek_id={G}", 200)
check('math', 'GET', '/analyse', 400)
check('math', 'GET', f'/analyse?api=http://evil.example&gesprek_id={G}', 403)
check('math', 'GET', f"/analyse?api={urllib.parse.quote(BASIS['api'])}&gesprek_id=bestaatniet", 404)
check('math', 'GET', '/docs', 200)
check('math', 'GET', '/docs/openapi.json', 200)

check('auth', 'POST', '/revoke', 200, {'token': tokens['refresh_token'], 'client_id': 'minipol-admin'}, formulier=True)

# every operation of the specs called at least once
for dienst, spec in SPECS.items():
    for pad, operaties in spec['paths'].items():
        for methode in operaties:
            if methode in ('get', 'post', 'put', 'delete') and (dienst, methode.upper(), pad) not in gedekt:
                print('FOUT niet getest:', dienst, methode.upper(), pad)
                fouten += 1

# for tests/browser.mjs
uitvoer = os.environ.get('TEST_UITVOER')
if uitvoer:
    json.dump({'gesprek': G, 'deelnemer': 'deelnemer-1', 'deelnemers': 4, 'zichtbaar': len(stellingen) - 1}, open(uitvoer, 'w'))

print(f'api.py: {fouten} fouten')
sys.exit(1 if fouten else 0)
