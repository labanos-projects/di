// ─── Player accounts: session, login, invite activation, my account ─────────
// Talks to php/auth.php. The session token lives in localStorage under
// 'di_session' and is shared with admin.html (same origin).

const SESSION_KEY = 'di_session';

function loadSession() {
  try { return JSON.parse(localStorage.getItem(SESSION_KEY)) || null; } catch (e) { return null; }
}
function saveSession(s) {
  try {
    if (s) localStorage.setItem(SESSION_KEY, JSON.stringify(s));
    else   localStorage.removeItem(SESSION_KEY);
  } catch (e) {}
  window.dispatchEvent(new Event('di-session'));
}

async function authCall(body, token) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res  = await fetch(`${API}/auth.php`, { method: 'POST', headers, body: JSON.stringify(body) });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Something went wrong');
  return data;
}

// Hook: current session ({ token, account }) or null. Re-validates on load.
function useSession() {
  const [session, setSession] = React.useState(loadSession);

  React.useEffect(() => {
    const sync = () => setSession(loadSession());
    window.addEventListener('di-session', sync);
    window.addEventListener('storage', sync);
    return () => {
      window.removeEventListener('di-session', sync);
      window.removeEventListener('storage', sync);
    };
  }, []);

  // Refresh account details once; drop the session if the server rejects it.
  React.useEffect(() => {
    const s = loadSession();
    if (!s || !s.token) return;
    fetch(`${API}/auth.php?action=me`, { headers: { Authorization: `Bearer ${s.token}` } })
      .then(r => {
        if (r.status === 401) { saveSession(null); return null; }
        return r.ok ? r.json() : null;
      })
      .then(account => { if (account) saveSession({ token: s.token, account }); })
      .catch(() => {});
  }, []);

  return session;
}

async function logout() {
  const s = loadSession();
  try { if (s) await authCall({ action: 'logout' }, s.token); } catch (e) {}
  saveSession(null);
}

// ── Small form bits ────────────────────────────────────────────────────────
function Field({ label, ...props }) {
  return (
    <label className="block mb-3">
      <span className="block text-xs font-medium text-slate-500 mb-1">{label}</span>
      <input {...props}
        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-500" />
    </label>
  );
}

function Card({ title, children }) {
  return (
    <div className="max-w-sm mx-auto px-4">
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        {title && <h2 className="text-lg font-bold text-slate-800 mb-4">{title}</h2>}
        {children}
      </div>
    </div>
  );
}

function SubmitButton({ busy, children }) {
  return (
    <button type="submit" disabled={busy}
      className="w-full bg-slate-900 hover:bg-slate-700 disabled:opacity-50 text-white rounded-lg px-4 py-2 text-sm font-semibold transition">
      {busy ? 'Please wait…' : children}
    </button>
  );
}

// ── Login ──────────────────────────────────────────────────────────────────
function LoginView({ onDone }) {
  const [email, setEmail]       = React.useState('');
  const [password, setPassword] = React.useState('');
  const [busy, setBusy]         = React.useState(false);
  const [error, setError]       = React.useState('');

  async function submit(e) {
    e.preventDefault();
    setBusy(true); setError('');
    try {
      const { token, account } = await authCall({ action: 'login', email, password });
      saveSession({ token, account });
      onDone();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card title="Log in">
      <form onSubmit={submit}>
        <Field label="Email" type="email" autoComplete="email" value={email}
          onChange={e => setEmail(e.target.value)} required autoFocus />
        <Field label="Password" type="password" autoComplete="current-password" value={password}
          onChange={e => setPassword(e.target.value)} required />
        {error && <p className="text-sm text-red-600 mb-3">{error}</p>}
        <SubmitButton busy={busy}>Log in</SubmitButton>
      </form>
      <p className="text-xs text-slate-400 mt-4">
        First time? Use the personal invite link you were sent. Forgot your password? Ask the
        organiser for a new link.
      </p>
    </Card>
  );
}

// ── Invite link: set email + password ──────────────────────────────────────
function ActivateView({ token, onDone }) {
  const [invite, setInvite]     = React.useState(null);
  const [loadErr, setLoadErr]   = React.useState('');
  const [email, setEmail]       = React.useState('');
  const [password, setPassword] = React.useState('');
  const [confirm, setConfirm]   = React.useState('');
  const [busy, setBusy]         = React.useState(false);
  const [error, setError]       = React.useState('');

  React.useEffect(() => {
    fetch(`${API}/auth.php?action=invite&token=${encodeURIComponent(token)}`)
      .then(async r => {
        const d = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(d.error || 'Invalid link');
        setInvite(d);
        if (d.email) setEmail(d.email);
      })
      .catch(err => setLoadErr(err.message));
  }, [token]);

  async function submit(e) {
    e.preventDefault();
    if (password !== confirm) { setError('The two passwords don’t match'); return; }
    setBusy(true); setError('');
    try {
      const { token: session, account } = await authCall({ action: 'activate', token, email, password });
      saveSession({ token: session, account });
      onDone(account);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  if (loadErr) return <Card title="Invite link"><p className="text-sm text-red-600">{loadErr}</p></Card>;
  if (!invite)  return <Spinner />;

  return (
    <Card>
      <div className="flex items-center gap-2 mb-1">
        <h2 className="text-lg font-bold text-slate-800">Welcome, {invite.player_name.split(' ')[0]}</h2>
        <TeamBadge team={invite.team} />
      </div>
      <p className="text-sm text-slate-500 mb-4">
        {invite.activated
          ? 'Choose a new password for your Damsgaard Invitational account.'
          : `This is ${invite.player_name}’s account. Choose the email and password you’ll log in with.`}
      </p>
      <form onSubmit={submit}>
        <Field label="Email" type="email" autoComplete="email" value={email}
          onChange={e => setEmail(e.target.value)} required />
        <Field label="Password (min. 8 characters)" type="password" autoComplete="new-password" minLength={8}
          value={password} onChange={e => setPassword(e.target.value)} required />
        <Field label="Repeat password" type="password" autoComplete="new-password" minLength={8}
          value={confirm} onChange={e => setConfirm(e.target.value)} required />
        {error && <p className="text-sm text-red-600 mb-3">{error}</p>}
        <SubmitButton busy={busy}>{invite.activated ? 'Save new password' : 'Create my login'}</SubmitButton>
      </form>
    </Card>
  );
}

// ── My account ─────────────────────────────────────────────────────────────
function AccountView({ session, onOpenProfile, onLoggedOut }) {
  const [current, setCurrent] = React.useState('');
  const [next, setNext]       = React.useState('');
  const [busy, setBusy]       = React.useState(false);
  const [msg, setMsg]         = React.useState(null);

  if (!session) return null;
  const a = session.account;

  async function changePassword(e) {
    e.preventDefault();
    setBusy(true); setMsg(null);
    try {
      await authCall({ action: 'change_password', current_password: current, new_password: next }, session.token);
      setMsg({ ok: true, text: 'Password changed.' });
      setCurrent(''); setNext('');
    } catch (err) {
      setMsg({ ok: false, text: err.message });
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="max-w-sm mx-auto px-4 space-y-4">
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <div className="flex items-center gap-2 mb-1">
          <h2 className="text-lg font-bold text-slate-800">{a.player_name}</h2>
          <TeamBadge team={a.team} />
        </div>
        <p className="text-sm text-slate-500 mb-4">{a.email}</p>
        <div className="flex gap-2">
          <button onClick={() => onOpenProfile(a.player_id)}
            className="flex-1 bg-slate-900 hover:bg-slate-700 text-white rounded-lg px-4 py-2 text-sm font-semibold transition">
            My profile &amp; {THIS_YEAR} shop
          </button>
          <button onClick={async () => { await logout(); onLoggedOut(); }}
            className="px-4 py-2 rounded-lg text-sm font-semibold text-slate-600 border border-slate-300 hover:bg-slate-50 transition">
            Log out
          </button>
        </div>
      </div>

      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h3 className="text-sm font-bold text-slate-700 mb-3">Change password</h3>
        <form onSubmit={changePassword}>
          <Field label="Current password" type="password" autoComplete="current-password"
            value={current} onChange={e => setCurrent(e.target.value)} required />
          <Field label="New password (min. 8 characters)" type="password" autoComplete="new-password" minLength={8}
            value={next} onChange={e => setNext(e.target.value)} required />
          {msg && <p className={`text-sm mb-3 ${msg.ok ? 'text-green-600' : 'text-red-600'}`}>{msg.text}</p>}
          <SubmitButton busy={busy}>Change password</SubmitButton>
        </form>
      </div>
    </div>
  );
}
