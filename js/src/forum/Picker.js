import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { api, tokenFor, recentKeys, remember } from './expressions';

const tabs = {
  recent: '最近', favorites: '收藏', unicode: 'Emoji', kaomoji: '颜文字',
  mine: '我的图片', shared: '共享图片', 'my-text': '我的文字', 'shared-text': '共享文字',
};

export default class Picker extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    this.scope = recentKeys().length ? 'recent' : 'kaomoji';
    this.items = [];
    this.query = this.tag = '';
    this.next = null;
    this.requestId = 0;
    this.alive = true;
    this.load();
  }
  onremove(vnode) {
    this.alive = false;
    this.requestId++;
    clearTimeout(this.searchTimer);
    super.onremove(vnode);
  }
  className() { return 'ExpressionPickerModal Modal--large'; }
  title() { return '表情与贴纸'; }
  onsubmit(event) { event.preventDefault(); }

  async load(append = false) {
    const version = ++this.requestId;
    this.loading = true;
    if (!append) this.items = [];
    try {
      let page;
      if (this.scope === 'recent') {
        page = await app.request({ method: 'POST', url: api('resolve'), body: { items: recentKeys() } });
      } else {
        const params = new URLSearchParams({ scope: this.scope, q: this.query, tag: this.tag, limit: '24' });
        if (append && this.next) params.set('before', this.next);
        page = await app.request({ method: 'GET', url: api(this.scope === 'favorites' ? 'favorites' : 'catalog') + '?' + params });
      }
      if (!this.alive || version !== this.requestId) return;
      this.items = append ? [...this.items, ...page.items] : page.items;
      this.next = page.next || null;
    } catch (_) {
      // Flarum presents the server error; stale requests never replace the current tab.
    } finally {
      if (this.alive && version === this.requestId) { this.loading = false; m.redraw(); }
    }
  }

  search(key, value) {
    this[key] = value;
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.load(), 200);
  }

  insert(item, mode) {
    if (item.kind === 'image' && !item.ready) return;
    this.attrs.onInsert(tokenFor(item, mode));
    remember(item);
    this.hide();
  }

  async favorite(item) {
    if (this.busy) return;
    this.busy = true;
    try {
      await app.request({ method: item.favorite ? 'DELETE' : 'PUT', url: api('favorites/' + item.kind + '/' + item.id) });
      item.favorite = !item.favorite;
      if (this.scope === 'favorites') await this.load();
    } catch (_) {
      // Preserve the displayed state on failure.
    } finally { this.busy = false; m.redraw(); }
  }

  async pruneFavorites() {
    this.busy = true;
    try {
      await app.request({ method: 'DELETE', url: api('favorites') });
      await this.load();
    } catch (_) { /* Flarum presents the server error. */ }
    finally { this.busy = false; m.redraw(); }
  }

  async prepare(item) {
    this.busy = true;
    try {
      Object.assign(item, await app.request({ method: 'POST', url: api('images/' + item.id + '/prepare') }));
    } catch (_) {
      // The original attachment remains available for management.
    } finally { this.busy = false; m.redraw(); }
  }

  editImage(item) {
    this.imageEdit = item;
    this.alias = (item.shortcode || '').replace(/^:u[0-9]+_/, '').replace(/:$/, '');
    this.mode = item.defaultMode;
  }

  async saveImage() {
    this.busy = true;
    const item = this.imageEdit;
    try {
      Object.assign(item, await app.request({ method: 'PATCH', url: api('images/' + item.id), body: {
        alias: this.alias, mode: this.mode, version: item.optionVersion, assetVersion: item.assetVersion,
      } }));
      this.imageEdit = null;
    } catch (_) {
      // Keep edit values so the user can correct or refresh them.
    } finally { this.busy = false; m.redraw(); }
  }

  editText(item = null) {
    this.textEdit = item || {};
    this.textName = item?.name || '';
    this.textBody = item?.text || '';
    this.textCategory = item?.category || '';
    this.textShared = item?.shared || false;
  }

  async saveText(remove = false) {
    const item = this.textEdit;
    this.busy = true;
    try {
      await app.request({ method: remove ? 'DELETE' : item.id ? 'PATCH' : 'POST', url: api('texts' + (item.id ? '/' + item.id : '')),
        body: { name: this.textName, body: this.textBody, category: this.textCategory, shared: this.textShared, version: item.version } });
      this.textEdit = null;
      await this.load();
    } catch (_) {
      // Keep unsaved text in the form.
    } finally { this.busy = false; m.redraw(); }
  }

  card(item) {
    const image = item.kind === 'image';
    return <article className={'ExpressionPicker-card ' + (image ? '' : 'ExpressionPicker-textCard')} key={item.key}>
      <button type="button" className="Button Button--link ExpressionPicker-insert" disabled={this.busy || image && !item.ready}
        onclick={() => this.insert(item)} aria-label={'插入 ' + item.name} title={item.name}>
        {image ? <img src={item.preview} alt={item.name} loading="lazy" decoding="async" />
          : <span className={item.kind === 'unicode' ? 'ExpressionPicker-unicode' : 'ExpressionPicker-text'}>{item.text}</span>}
        <span className="ExpressionPicker-name">{item.name}</span>
      </button>
      <div className="ExpressionPicker-cardActions">
        {Button.component({ className: 'Button Button--link', disabled: this.busy, onclick: () => this.favorite(item), 'aria-label': item.favorite ? '取消收藏' : '收藏' }, item.favorite ? '★' : '☆')}
        {image && item.ready && Button.component({ className: 'Button Button--link', disabled: this.busy, onclick: () => this.insert(item, 'sticker') }, '贴纸')}
        {image && !item.ready && <small>等待处理</small>}
        {image && item.canManage && !item.ready && Button.component({ className: 'Button Button--link', disabled: this.busy, onclick: () => this.prepare(item) }, '处理')}
        {item.canManage && Button.component({ className: 'Button Button--link', disabled: this.busy, onclick: () => image ? this.editImage(item) : this.editText(item) }, '设置')}
      </div>
      {item.shortcode && <small className="ExpressionPicker-shortcode">{item.shortcode}</small>}
    </article>;
  }

  content() {
    const pictures = ['mine', 'shared'].includes(this.scope);
    return <div className="Modal-body ExpressionPicker">
      <nav className="ExpressionPicker-tabs" aria-label="表情来源">
        {Object.entries(tabs).map(([scope, label]) => Button.component({
          className: 'Button ' + (scope === this.scope ? 'Button--primary' : ''), 'aria-pressed': scope === this.scope,
          disabled: this.busy, onclick: () => { clearTimeout(this.searchTimer); this.scope = scope; this.query = this.tag = ''; this.load(); },
        }, label))}
      </nav>
      {this.scope === 'favorites' && Button.component({ className: 'Button', disabled: this.busy, onclick: () => this.pruneFavorites() }, '清理失效收藏')}
      {!['recent', 'favorites'].includes(this.scope) && <div className="ExpressionPicker-search">
        <input className="FormControl" aria-label="搜索名称或附件 ID" placeholder={pictures ? '搜索名称前缀或附件 ID' : '搜索名称或颜文字'} value={this.query} oninput={(e) => this.search('query', e.target.value)} />
        <input className="FormControl" aria-label="分类标签" placeholder="分类标签" value={this.tag} oninput={(e) => this.search('tag', e.target.value)} />
      </div>}
      {this.scope === 'mine' && <p className="helpText">请先在用户菜单的「附件库」上传图片并添加 emoji 标签，然后在此选择插入。</p>}
      {this.scope === 'my-text' && Button.component({ className: 'Button', disabled: this.busy, onclick: () => this.editText() }, '添加颜文字')}
      {this.imageEdit && <section className="ExpressionPicker-edit" aria-label="图片表情设置">
        <label>短码名称<input className="FormControl" value={this.alias} maxlength="32" placeholder="例如 cat_wave" oninput={(e) => { this.alias = e.target.value; }} /></label>
        <label>默认插入方式<select className="FormControl" value={this.mode} onchange={(e) => { this.mode = e.target.value; }}><option value="inline">行内表情</option><option value="sticker">大贴纸</option></select></label>
        {Button.component({ className: 'Button Button--primary', disabled: this.busy, onclick: () => this.saveImage() }, '保存')}
        {Button.component({ className: 'Button', disabled: this.busy, onclick: () => { this.imageEdit = null; } }, '取消')}
      </section>}
      {this.textEdit && <section className="ExpressionPicker-edit" aria-label="颜文字编辑">
        <input className="FormControl" aria-label="名称" placeholder="名称" maxlength="80" value={this.textName} oninput={(e) => { this.textName = e.target.value; }} />
        <textarea className="FormControl" aria-label="颜文字内容" placeholder="颜文字内容" maxlength="256" value={this.textBody} oninput={(e) => { this.textBody = e.target.value; }} />
        <input className="FormControl" aria-label="颜文字分类" placeholder="分类" maxlength="32" value={this.textCategory} oninput={(e) => { this.textCategory = e.target.value; }} />
        <label><input type="checkbox" checked={this.textShared} onchange={(e) => { this.textShared = e.target.checked; }} /> 允许其他登录用户使用</label>
        {Button.component({ className: 'Button Button--primary', disabled: this.busy, onclick: () => this.saveText() }, '保存')}
        {this.textEdit.id && Button.component({ className: 'Button', disabled: this.busy, onclick: () => this.saveText(true) }, '删除条目')}
        {Button.component({ className: 'Button', disabled: this.busy, onclick: () => { this.textEdit = null; } }, '取消')}
      </section>}
      {this.busy && <p role="status">正在处理…</p>}
      <div className="ExpressionPicker-grid">{this.items.map((item) => this.card(item))}</div>
      {this.loading && LoadingIndicator.component()}
      {!this.loading && !this.items.length && <p className="helpText">当前没有可用条目。</p>}
      {this.next && Button.component({ className: 'Button', disabled: this.loading, onclick: () => this.load(true) }, '加载更多')}
    </div>;
  }
}
