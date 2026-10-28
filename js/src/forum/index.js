import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import TextEditor from 'flarum/common/components/TextEditor';
import TextEditorButton from 'flarum/common/components/TextEditorButton';
import CommentPost from 'flarum/forum/components/CommentPost';
import KeyboardNavigatable from 'flarum/common/utils/KeyboardNavigatable';
import Picker from './Picker';
import { api, tokenFor, remember, groupStickers } from './expressions';

app.initializers.add('local/expression-system', () => {
  extend(TextEditor.prototype, 'toolbarItems', function (items) {
    if (!app.session.user) return;
    items.remove('emoji');
    items.remove('media-library-emoji');
    items.add('expression-system', TextEditorButton.component({
      icon: 'far fa-smile', onclick: () => app.modal.show(Picker, {
        onInsert: (text) => this.attrs.composer.editor.insertAtCursor(text),
      }),
    }, '表情与贴纸'));
  });
  extend(TextEditor.prototype, 'buildEditorParams', function (params) {
    const state = this.expressionCompletion = { items: [], index: 0, version: 0, alive: true };
    params.inputListeners.push(() => {
      const editor = this.attrs.composer.editor;
      const selection = editor.getSelectionRange();
      const match = selection[0] === selection[1] && /(?:^|\s)(:u[0-9]+_[a-z0-9_-]*)$/.exec(editor.getLastNChars(80));
      const prefix = match ? match[1] : '';
      if (prefix === state.prefix) return;
      state.prefix = prefix;
      state.items = [];
      const version = ++state.version;
      clearTimeout(state.timer);
      m.redraw();
      if (!prefix) return;
      state.timer = setTimeout(async () => {
        try {
          const page = await app.request({ method: 'GET', url: api('catalog') + '?shortcode=' + encodeURIComponent(prefix) });
          if (!state.alive || version !== state.version) return;
          state.items = page.items.filter((item) => item.ready);
          state.index = 0;
          m.redraw();
        } catch (_) { /* Flarum presents the server error. */ }
      }, 200);
    });
  });
  const complete = function (item) {
    const state = this.expressionCompletion;
    const editor = this.attrs.composer.editor;
    const cursor = editor.getSelectionRange()[0];
    editor.replaceBeforeCursor(cursor - state.prefix.length, tokenFor(item) + ' ');
    remember(item);
    state.items = [];
    state.prefix = '';
    state.version++;
    m.redraw();
  };
  extend(TextEditor.prototype, 'oncreate', function () {
    const state = this.expressionCompletion;
    new KeyboardNavigatable().when(() => state.items.length > 0)
      .onUp(() => { state.index = (state.index + state.items.length - 1) % state.items.length; m.redraw(); })
      .onDown(() => { state.index = (state.index + 1) % state.items.length; m.redraw(); })
      .onSelect(() => complete.call(this, state.items[state.index]))
      .onCancel(() => { state.items = []; m.redraw(); })
      .bindTo(this.$('.TextEditor-editor'));
  });
  extend(TextEditor.prototype, 'view', function (vnode) {
    const state = this.expressionCompletion;
    if (!state?.items.length) return;
    vnode.children.push(<div className="ExpressionShortcuts" role="listbox" aria-label="图片表情短码">
      {state.items.map((item, index) => <button type="button" className={'Button Button--link ' + (index === state.index ? 'active' : '')}
        role="option" aria-selected={index === state.index} key={item.key} onclick={() => complete.call(this, item)}>
        <img src={item.preview} alt="" />{item.shortcode} · {item.name}
      </button>)}
    </div>);
  });
  extend(TextEditor.prototype, 'onremove', function () {
    if (this.expressionCompletion) {
      this.expressionCompletion.alive = false;
      clearTimeout(this.expressionCompletion.timer);
    }
  });
  ['oncreate', 'onupdate'].forEach((event) => extend(CommentPost.prototype, event, function () {
    groupStickers(this.element?.querySelector('.Post-body'));
  }));
  override(s9e.TextFormatter, 'preview', (original, text, element) => {
    original(text, element);
    groupStickers(element);
  });
}, -10);
