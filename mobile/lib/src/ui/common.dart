// Thème (couleurs nationales orange et vert, contraste AA, cibles tactiles ≥ 48 dp) et éléments communs.

import 'package:flutter/material.dart';

import '../data/local_db.dart';

const brandOrange = Color(0xFFD35400);
const brandGreen = Color(0xFF128A3E);
const brandGreenDark = Color(0xFF0D6B30);

ThemeData appTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: brandGreen, primary: brandGreenDark, secondary: brandOrange);
  return ThemeData(
    colorScheme: scheme,
    useMaterial3: true,
    appBarTheme: const AppBarTheme(backgroundColor: brandGreenDark, foregroundColor: Colors.white),
    inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(minimumSize: const Size(64, 48), backgroundColor: brandOrange)),
    outlinedButtonTheme: OutlinedButtonThemeData(style: OutlinedButton.styleFrom(minimumSize: const Size(64, 48))),
    materialTapTargetSize: MaterialTapTargetSize.padded,
  );
}

/// État d'une affectation tel qu'affiché à l'agent (libellé, couleur, icône).
({String label, Color color, IconData icon}) statusOf(Map<String, Object?> a) {
  final local = a['local_status'] as String?;
  final outbox = a['outbox_state'] as String?;
  if (local == null) return (label: 'À collecter', color: Colors.grey.shade700, icon: Icons.radio_button_unchecked);
  return switch (local) {
    LocalStatus.brouillon when outbox == OutboxState.echec => (label: 'Refusée : à corriger', color: Colors.red.shade800, icon: Icons.error),
    LocalStatus.brouillon => (label: 'Brouillon', color: brandOrange, icon: Icons.edit_note),
    LocalStatus.terminee => (label: "Terminée — en attente d'envoi", color: Colors.blue.shade800, icon: Icons.cloud_upload),
    LocalStatus.soumise => (label: 'Envoyée — en contrôle', color: brandGreenDark, icon: Icons.cloud_done),
    LocalStatus.validee => (label: 'Validée', color: brandGreenDark, icon: Icons.verified),
    LocalStatus.invalidee => (label: 'À corriger', color: Colors.red.shade800, icon: Icons.assignment_return),
    LocalStatus.enConflit => (label: 'Conflit : action requise', color: Colors.purple.shade800, icon: Icons.warning_amber),
    _ => (label: local, color: Colors.grey.shade700, icon: Icons.help_outline),
  };
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.row, {super.key});
  final Map<String, Object?> row;

  @override
  Widget build(BuildContext context) {
    final s = statusOf(row);
    return Semantics(
      label: 'État : ${s.label}',
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(s.icon, size: 18, color: s.color),
        const SizedBox(width: 4),
        Flexible(child: Text(s.label, style: TextStyle(color: s.color, fontWeight: FontWeight.w600))),
      ]),
    );
  }
}

void showMessage(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message), backgroundColor: error ? Colors.red.shade800 : null, duration: const Duration(seconds: 5)));
}

Future<bool> confirm(BuildContext context, String title, String message, {String ok = 'Confirmer'}) async =>
    await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(title),
        content: Text(message),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Annuler')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(ok)),
        ],
      ),
    ) ??
    false;

String formatDateTime(DateTime? d) {
  if (d == null) return 'jamais';
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(d.day)}/${two(d.month)}/${d.year} à ${two(d.hour)}:${two(d.minute)}';
}
