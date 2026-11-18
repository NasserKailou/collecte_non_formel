// Connexion en ligne, déverrouillage hors ligne et changement de mot de passe (obligatoire à la première connexion).

import 'package:flutter/material.dart';

import '../app_state.dart';
import '../net/api_client.dart';
import '../services/auth_service.dart';
import 'common.dart';
import 'server_check_dialog.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.app});
  final AppState app;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _id = TextEditingController();
  final _pwd = TextEditingController();
  bool _busy = false;
  bool _hide = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    final known = widget.app.auth.knownAccount;
    if (known != null) _id.text = known['matricule'] as String;
  }

  @override
  void dispose() {
    _id.dispose();
    _pwd.dispose();
    super.dispose();
  }

  Future<void> _submit({required bool offline}) async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final s = offline ? await widget.app.auth.unlockOffline(_pwd.text) : await widget.app.auth.loginOnline(_id.text, _pwd.text);
      if (!mounted) return;
      if (s.mustChangePassword && s.online) {
        final changed = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => ChangePasswordScreen(app: widget.app, current: _pwd.text)));
        if (changed != true) return;
      }
      _pwd.clear();
      widget.app.signedIn(s);
    } on ApiException catch (e) {
      setState(() => _error = e.isNetwork ? '${e.message} Si vous avez déjà travaillé sur cette tablette, utilisez « Déverrouiller hors ligne ».' : e.message);
    } on AuthException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'Erreur inattendue : $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = widget.app.auth;
    final known = auth.knownAccount;
    final canOffline = auth.offlineUnlockAllowed;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Form(
                key: _form,
                child: AutofillGroup(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    const Icon(Icons.assignment, size: 56, color: brandGreenDark),
                    const SizedBox(height: 8),
                    Text('Recensement EFTP non formel', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: 4),
                    const Text('Application de collecte des agents recenseurs', textAlign: TextAlign.center),
                    const SizedBox(height: 24),
                    TextFormField(
                      controller: _id,
                      decoration: const InputDecoration(labelText: 'Matricule ou téléphone', prefixIcon: Icon(Icons.person)),
                      autofillHints: const [AutofillHints.username],
                      textInputAction: TextInputAction.next,
                      validator: (v) => (v == null || v.trim().isEmpty) ? 'Identifiant obligatoire.' : null,
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _pwd,
                      obscureText: _hide,
                      decoration: InputDecoration(
                        labelText: 'Mot de passe',
                        prefixIcon: const Icon(Icons.lock),
                        suffixIcon: IconButton(
                          tooltip: _hide ? 'Afficher le mot de passe' : 'Masquer le mot de passe',
                          icon: Icon(_hide ? Icons.visibility : Icons.visibility_off),
                          onPressed: () => setState(() => _hide = !_hide),
                        ),
                      ),
                      autofillHints: const [AutofillHints.password],
                      onFieldSubmitted: (_) => _submit(offline: false),
                      validator: (v) => (v == null || v.isEmpty) ? 'Mot de passe obligatoire.' : null,
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: 16),
                      Semantics(
                        liveRegion: true,
                        child: Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(color: Colors.red.shade50, border: Border(left: BorderSide(color: Colors.red.shade800, width: 4))),
                          child: Text(_error!, style: TextStyle(color: Colors.red.shade900)),
                        ),
                      ),
                    ],
                    const SizedBox(height: 24),
                    FilledButton.icon(
                      onPressed: _busy ? null : () => _submit(offline: false),
                      icon: _busy
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Icon(Icons.login),
                      label: const Text('Se connecter (en ligne)'),
                    ),
                    const SizedBox(height: 8),
                    TextButton.icon(
                      onPressed: _busy ? null : () => showServerCheck(context, widget.app),
                      icon: const Icon(Icons.wifi_find),
                      label: const Text('Tester la connexion au serveur'),
                    ),
                    if (known != null) ...[
                      const SizedBox(height: 12),
                      OutlinedButton.icon(
                        onPressed: _busy || !canOffline ? null : () => _submit(offline: true),
                        icon: const Icon(Icons.lock_open),
                        label: Text('Déverrouiller hors ligne (${known['matricule']})'),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        canOffline
                            ? 'Hors ligne : encore ${AuthService.offlineDaysLeft(known)} jour(s) avant reconnexion obligatoire.'
                            : "Connexion en ligne nécessaire (délai hors ligne dépassé, déconnexion ou trop d'essais).",
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ]),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key, required this.app, required this.current});
  final AppState app;
  final String current;

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _form = GlobalKey<FormState>();
  final _new = TextEditingController();
  final _again = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _new.dispose();
    _again.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.app.auth.changePassword(widget.current, _new.text);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Même règle que le serveur (AuthService::assertPasswordPolicy) : 10 caractères, lettres et chiffres.
  static String? _policy(String? v) {
    if (v == null || v.length < 10) return '10 caractères au moins.';
    if (!RegExp('[A-Za-z]').hasMatch(v) || !RegExp(r'\d').hasMatch(v)) return 'Lettres et chiffres obligatoires.';
    return null;
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Nouveau mot de passe')),
        body: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _form,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('Le mot de passe provisoire doit être remplacé avant de continuer. Il servira aussi à déverrouiller la tablette hors ligne.'),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _new,
                  obscureText: true,
                  decoration: const InputDecoration(labelText: 'Nouveau mot de passe', helperText: '10 caractères au moins, avec des lettres et des chiffres.'),
                  validator: _policy,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _again,
                  obscureText: true,
                  decoration: const InputDecoration(labelText: 'Confirmer le mot de passe'),
                  validator: (v) => v != _new.text ? 'Les deux saisies sont différentes.' : null,
                ),
                if (_error != null) Padding(padding: const EdgeInsets.only(top: 16), child: Text(_error!, style: TextStyle(color: Colors.red.shade900))),
                const SizedBox(height: 24),
                FilledButton(onPressed: _busy ? null : _submit, child: const Text('Enregistrer')),
              ]),
            ),
          ),
        ),
      );
}
