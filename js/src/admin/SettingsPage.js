import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

export default class SettingsPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = true;
    app.request({ method: 'GET', url: this.endpoint() }).then((data) => {
      this.data = data;
      this.loading = false;
      m.redraw();
    }, () => { this.loading = false; m.redraw(); });
  }
  endpoint() { return app.forum.attribute('apiUrl') + '/expression-system/settings'; }
  async save(event) {
    event.preventDefault();
    this.saving = true;
    this.saved = false;
    try {
      this.data = await app.request({ method: 'POST', url: this.endpoint(), body: { display: this.data.display, processing: this.data.processing } });
      this.saved = true;
    } catch (_) {
      // Server validation uses the same schema, including related limits.
    } finally { this.saving = false; m.redraw(); }
  }
  field(section, key, field) {
    const values = this.data[section];
    const attrs = { className: 'FormControl', id: 'expression-' + key, value: values[key],
      onchange: (e) => { values[key] = field.options ? e.target.value : Number(e.target.value); } };
    return <div className="Form-group" key={key}>
      <label for={attrs.id}>{field.label}{field.unit ? '（' + field.unit + '）' : ''}</label>
      {field.options
        ? <select {...attrs}>{Object.entries(field.options).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select>
        : <input {...attrs} type="number" min={field.min} max={field.max} step={field.step || 1} oninput={attrs.onchange} />}
    </div>;
  }
  preview() {
    const d = this.data.display;
    const sample = (ratio) => {
      const height = Math.min(d.inlineCap, d.inlineEm * 16, d.inlineWidth * 16 / ratio);
      return <span style={{
        display: 'inline-block', width: height * ratio + 'px', height: height + 'px',
        background: 'var(--control-bg)', border: '1px solid currentColor', borderRadius: '3px',
        verticalAlign: -d.baseline + 'em', marginInline: d.gap + 'em',
      }} aria-label="表情占位图"></span>;
    };
    return <div style={{ padding: '16px', marginBottom: '20px', background: 'var(--body-bg)', lineHeight: '1.8', fontSize: '16px' }}>
      今天的工作终于完成了{sample(1)}，给自己一点休息的时间。A quiet moment{sample(2)}，明天继续加油{sample(.5)}。
      <div style={{ display: 'flex', flexDirection: d.stickerLayout === 'wrap' ? 'row' : 'column', flexWrap: 'wrap',
        alignItems: d.stickerLayout === 'wrap' ? 'flex-start' : ({ left: 'flex-start', center: 'center', right: 'flex-end' }[d.stickerAlign]),
        justifyContent: d.stickerLayout === 'wrap' ? ({ left: 'flex-start', center: 'center', right: 'flex-end' }[d.stickerAlign]) : 'flex-start',
        gap: d.stickerGap + 'px', marginTop: '12px' }}>
        {[1, 2, .5].map((ratio) => <span style={{
          width: Math.min(d.stickerWidth, d.stickerHeight * ratio) + 'px',
          height: Math.min(d.stickerHeight, d.stickerWidth / ratio) + 'px', maxWidth: '100%',
          display: 'grid', placeItems: 'center', background: 'var(--control-bg)', border: '1px solid currentColor',
        }}>{ratio === 1 ? '1:1' : ratio === 2 ? '2:1' : '1:2'}</span>)}
      </div>
    </div>;
  }
  content() {
    if (this.loading) return <div className="container">{LoadingIndicator.component()}</div>;
    if (!this.data) return <div className="container">设置未能加载，请刷新页面。</div>;
    return <div className="container" style={{ maxWidth: '850px' }}>
      <form onsubmit={(event) => this.save(event)}>
        <h3>正文显示预览</h3>
        {this.preview()}
        <h3>正文显示</h3>
        {Object.entries(this.data.displaySchema).map(([key, field]) => this.field('display', key, field))}
        <h3>上传限制与图片处理</h3>
        <p className="helpText">质量参数不是体积缩小比例。原图保留，处理图计入所属用户配额。新编码参数作用于新版本；已有表情用维护命令分批重建。</p>
        {Object.entries(this.data.processingSchema).map(([key, field]) => this.field('processing', key, field))}
        <p className="helpText">动态 GIF 保留原始动画码流，选择器使用静态预览；不重压缩或缩放动画，静态编码质量和处理图边长/体积参数不作用于动画。GIF 专用大小、画布、帧数、累计像素和单轮时长限制也作用于普通 GIF 附件上传。旧表情需要维护命令重建后采用新策略。</p>
        <p className="helpText">单轮时长不限制循环播放总时长；同页多张动画的开销会累加。PHP 上传上限：{this.data.capabilities.uploadMax}，请求上限：{this.data.capabilities.postMax}。</p>
        {Button.component({ className: 'Button Button--primary', type: 'submit', loading: this.saving }, '保存设置')}
        {this.saved && <p role="status">设置已保存。刷新论坛页面后显示设置生效。</p>}
      </form>
    </div>;
  }
}
