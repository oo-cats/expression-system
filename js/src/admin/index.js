import app from 'flarum/admin/app';
import SettingsPage from './SettingsPage';

app.initializers.add('local/expression-system', () => {
  app.extensionData.for('local-expression-system').registerPage(SettingsPage);
});
