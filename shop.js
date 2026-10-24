// ─── Player shop (team merchandise via GolfExperten) ─────────────────────────
// Shown on a player's OWN profile page when logged in (see player.js).
// Talks to php/shop.php. Prices are DKK; the allowance counts against `price`
// (our discounted price) and anything above it is billed to the player by
// GolfExperten. Unused allowance is lost.

function fmtKr(n) {
  return `${Math.round(n || 0).toLocaleString('da-DK')} kr`;
}

function fmtDeadline(s) {
  if (!s) return null;
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

async function shopFetch(token, path, opts = {}) {
  const res = await fetch(`${API}/shop.php${path}`, {
    ...opts,
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...(opts.headers || {}) },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Something went wrong');
  return data;
}

function ColourChip({ team }) {
  const t = TEAM[team];
  if (!t) return null;
  return (
    <span className={`inline-flex items-center gap-1 text-xs font-semibold ${t.text}`}>
      <span className={`w-2.5 h-2.5 rounded-full ${t.bg}`} /> {t.label}
    </span>
  );
}

function QtyStepper({ value, onChange, min = 0, max = 10, disabled }) {
  const btn = 'w-7 h-7 rounded-md border border-slate-300 text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent';
  return (
    <div className="inline-flex items-center gap-2">
      <button type="button" className={btn} disabled={disabled || value <= min} onClick={() => onChange(value - 1)}>−</button>
      <span className="w-5 text-center mono text-sm">{value}</span>
      <button type="button" className={btn} disabled={disabled || value >= max} onClick={() => onChange(value + 1)}>+</button>
    </div>
  );
}

// One product with its order lines (one line per size).
function ShopProduct({ product, team, lines, setLines, locked }) {
  const mine   = lines.filter(l => l.product_id === product.id);
  const used   = mine.reduce((s, l) => s + l.qty, 0);
  const sized  = product.sizes.length > 0;
  const canAdd = !locked && used < product.max_qty && (sized || mine.length === 0);

  function update(idx, patch) {
    let n = -1;
    setLines(lines.map(l => {
      if (l.product_id !== product.id) return l;
      n++;
      return n === idx ? { ...l, ...patch } : l;
    }).filter(l => l.qty > 0));
  }
  function add() {
    const taken = new Set(mine.map(l => l.size));
    const size  = sized ? (product.sizes.find(s => !taken.has(s)) || product.sizes[0]) : '';
    setLines([...lines, { product_id: product.id, size, qty: 1 }]);
  }

  return (
    <div className={`border rounded-xl p-3 ${used ? 'border-slate-400 bg-slate-50' : 'border-slate-200'}`}>
      <div className="flex gap-3">
        <div className="w-16 h-16 shrink-0 rounded-lg bg-slate-100 overflow-hidden flex items-center justify-center text-2xl">
          {product.image_url
            ? <img src={product.image_url} alt="" className="w-full h-full object-cover" />
            : <span>🏌️</span>}
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-start justify-between gap-2">
            <div className="font-semibold text-slate-800">{product.name}</div>
            <div className="text-right shrink-0">
              <div className="font-bold mono text-slate-800">{fmtKr(product.price)}</div>
              {product.list_price && product.list_price > product.price &&
                <div className="text-xs text-slate-400 line-through mono">{fmtKr(product.list_price)}</div>}
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-0.5">
            {product.team_colored && <ColourChip team={team} />}
            {product.has_logo && <span className="text-xs text-slate-500">⛳ DI logo</span>}
            {sized && <span className="text-xs text-slate-400">Sizes {product.sizes.join(' · ')}</span>}
          </div>
          {product.description && <p className="text-xs text-slate-500 mt-1">{product.description}</p>}
        </div>
      </div>

      {mine.length > 0 && (
        <div className="mt-3 space-y-2">
          {mine.map((l, i) => (
            <div key={i} className="flex items-center justify-between gap-2 bg-white rounded-lg border border-slate-200 px-2 py-1.5">
              {sized
                ? <select value={l.size} disabled={locked} onChange={e => update(i, { size: e.target.value })}
                    className="border border-slate-300 rounded-md px-2 py-1 text-sm">
                    {product.sizes.map(s => <option key={s} value={s}>{s}</option>)}
                  </select>
                : <span className="text-xs text-slate-500">One size</span>}
              <QtyStepper value={l.qty} disabled={locked}
                max={l.qty + (product.max_qty - used)}
                onChange={q => update(i, { qty: q })} />
            </div>
          ))}
        </div>
      )}

      {canAdd && (
        <button type="button" onClick={add}
          className="mt-3 w-full text-sm font-semibold rounded-lg border border-dashed border-slate-300 py-1.5 text-slate-600 hover:bg-slate-100">
          {mine.length === 0 ? '+ Add' : '+ Add another size'}
        </button>
      )}
    </div>
  );
}

function ShopPanel({ year, session }) {
  const [state, setState]   = React.useState(null);   // response from ?action=my
  const [lines, setLines]   = React.useState([]);
  const [saved, setSaved]   = React.useState('[]');   // JSON of last saved lines (to detect changes)
  const [busy, setBusy]     = React.useState(false);
  const [msg, setMsg]       = React.useState(null);
  const [error, setError]   = React.useState(null);

  const normalise = ls => JSON.stringify(
    ls.filter(l => l.qty > 0).map(l => [l.product_id, l.size, l.qty]).sort());

  React.useEffect(() => {
    shopFetch(session.token, `?action=my&year=${year}`)
      .then(d => {
        setState(d);
        const ls = (d.order?.items || []).map(i => ({ product_id: i.product_id, size: i.size, qty: i.qty }));
        setLines(ls);
        setSaved(normalise(ls));
      })
      .catch(e => setError(e.message));
  }, [year, session.token]);

  if (error)  return <div className="bg-white rounded-2xl shadow-sm p-4 mb-4 text-sm text-red-600">{error}</div>;
  if (!state) return null;

  const { config, player } = state;

  // Closed shop (players only see a teaser; admins get a preview)
  if (!config.is_open && !state.preview) {
    if (!state.on_roster) return null;
    return (
      <div className="bg-white rounded-2xl shadow-sm p-4 mb-4">
        <div className="text-xs font-bold uppercase tracking-wide text-slate-400 mb-1">{year} Shop</div>
        <p className="text-sm text-slate-600">The {year} shop isn't open yet — you'll get {fmtKr(config.allowance)} to spend on team gear.</p>
      </div>
    );
  }
  if (!state.on_roster) {
    return (
      <div className="bg-white rounded-2xl shadow-sm p-4 mb-4">
        <div className="text-xs font-bold uppercase tracking-wide text-slate-400 mb-1">{year} Shop</div>
        <p className="text-sm text-slate-600">The shop is for players on the {year} roster.</p>
      </div>
    );
  }

  const products  = state.products || [];
  const priceOf   = id => (products.find(p => p.id === id) || {}).price || 0;
  const total     = lines.reduce((s, l) => s + l.qty * priceOf(l.product_id), 0);
  const allowance = config.allowance;
  const included  = Math.min(total, allowance);
  const extra     = Math.max(0, total - allowance);
  const left      = Math.max(0, allowance - total);
  const locked    = config.locked;
  const dirty     = normalise(lines) !== saved;
  const status    = state.order?.status || 'none';
  const deadline  = fmtDeadline(config.deadline);
  const pct       = allowance ? Math.min(100, Math.round(100 * included / allowance)) : 0;

  async function save(submit) {
    setBusy(true); setMsg(null);
    try {
      const d = await shopFetch(session.token, '', {
        method: 'POST',
        body: JSON.stringify({ action: 'save_order', year, items: lines, submit }),
      });
      setState({ ...state, order: d.order });
      setSaved(normalise(lines));
      setMsg({ ok: true, text: submit ? 'Order submitted — thanks!' : 'Saved as draft.' });
    } catch (e) {
      setMsg({ ok: false, text: e.message });
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="bg-white rounded-2xl shadow-sm mb-4 overflow-hidden">
      <div className="px-4 py-3 border-b border-slate-100 flex items-center justify-between gap-2">
        <div className="text-xs font-bold uppercase tracking-wide text-slate-400">{year} Team Shop</div>
        {status === 'submitted' && !dirty && <span className="text-xs font-semibold text-green-700 bg-green-100 rounded-full px-2 py-0.5">✓ Submitted</span>}
        {status === 'submitted' && dirty  && <span className="text-xs font-semibold text-amber-700 bg-amber-100 rounded-full px-2 py-0.5">Unsubmitted changes</span>}
        {status === 'draft' && <span className="text-xs font-semibold text-slate-600 bg-slate-100 rounded-full px-2 py-0.5">Draft — not submitted</span>}
      </div>

      <div className="p-4 space-y-4">
        {state.preview && (
          <div className="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-2">
            Admin preview — the shop is closed for players. Open it on the shop admin page.
          </div>
        )}

        <p className="text-sm text-slate-600">
          You have <b>{fmtKr(allowance)}</b> to spend on {TEAM[player.team]?.label.toLowerCase()} team gear.
          Anything above that is billed to you directly by GolfExperten. Unused allowance isn't paid out.
          {deadline && <> Order by <b>{deadline}</b>.</>}
        </p>
        {locked && <p className="text-sm font-semibold text-red-600">The order deadline has passed — orders are locked.</p>}

        {products.length === 0
          ? <p className="text-sm text-slate-400">No products yet.</p>
          : <div className="space-y-3">
              {products.map(p => <ShopProduct key={p.id} product={p} team={player.team}
                                   lines={lines} setLines={setLines} locked={locked} />)}
            </div>}

        {/* Totals */}
        <div className="rounded-xl bg-slate-50 border border-slate-200 p-3 space-y-2 sticky bottom-2">
          <div className="h-2 rounded-full bg-slate-200 overflow-hidden">
            <div className={`h-full ${extra ? 'bg-amber-500' : (TEAM[player.team]?.bg || 'bg-slate-600')}`} style={{ width: `${pct}%` }} />
          </div>
          <div className="flex justify-between text-sm">
            <span className="text-slate-500">Order total</span><span className="mono font-semibold">{fmtKr(total)}</span>
          </div>
          <div className="flex justify-between text-sm">
            <span className="text-slate-500">Covered by allowance</span><span className="mono">{fmtKr(included)} / {fmtKr(allowance)}</span>
          </div>
          {extra > 0
            ? <div className="flex justify-between text-sm font-semibold text-amber-700">
                <span>Extra — billed to you by GolfExperten</span><span className="mono">{fmtKr(extra)}</span>
              </div>
            : left > 0 && <div className="text-xs text-slate-400 text-right">{fmtKr(left)} left</div>}

          {msg && <p className={`text-sm ${msg.ok ? 'text-green-600' : 'text-red-600'}`}>{msg.text}</p>}

          {!locked && (
            <div className="flex gap-2 pt-1">
              <button type="button" disabled={busy || !dirty} onClick={() => save(false)}
                className="px-3 py-2 rounded-lg text-sm font-semibold border border-slate-300 text-slate-700 hover:bg-white disabled:opacity-40">
                Save draft
              </button>
              <button type="button" disabled={busy || lines.length === 0 || (status === 'submitted' && !dirty)} onClick={() => save(true)}
                className="flex-1 px-3 py-2 rounded-lg text-sm font-semibold bg-slate-900 text-white hover:bg-slate-700 disabled:opacity-40">
                {busy ? 'Saving…' : status === 'submitted' ? 'Submit changes' : 'Submit order'}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
