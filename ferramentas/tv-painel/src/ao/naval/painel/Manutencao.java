package ao.naval.painel;

import android.content.Context;
import android.provider.Settings;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/**
 * Depuração da TV (adb) fechada por omissão e aberta só quando o super administrador pede «Abrir manutenção da TV» no
 * portal (fecha sozinha no fim do prazo). Pergunta ao portal com a ligação do painel (files/ligacao.txt, escrita por
 * construir.ps1 na instalação): a resposta do painel traz «manutencao»: true|false. Sem resposta do portal, não mexe.
 */
final class Manutencao {
    private Manutencao() {
    }

    /** Pergunta ao portal e aplica; corre fora da thread principal. */
    static void verificar(Context c) {
        try {
            String ligacao = ler(new File(c.getFilesDir(), "ligacao.txt")).trim();
            int i = ligacao.indexOf("/sala/");
            int t = ligacao.indexOf("#t=");
            if (i < 0 || t < 0) return;
            URL api = new URL(ligacao.substring(0, i) + "/api/index.php/v1/sala");
            HttpURLConnection h = (HttpURLConnection) api.openConnection();
            h.setConnectTimeout(15000);
            h.setReadTimeout(20000);
            h.setRequestProperty("X-Painel-Codigo", ligacao.substring(t + 3));
            if (h.getResponseCode() != 200) return;
            String corpo;
            try (InputStream in = h.getInputStream()) {
                corpo = tudo(in);
            }
            boolean abrir = corpo.contains("\"manutencao\":true");
            if (!abrir && !corpo.contains("\"manutencao\":false")) return;   // resposta antiga ou inesperada: não mexe
            aplicar(c, abrir);
        } catch (Exception e) {
            Registo.escrever(c, "manutenção: " + e);
        }
    }

    static void aplicar(Context c, boolean abrir) {
        try {
            int agora = Settings.Global.getInt(c.getContentResolver(), Settings.Global.ADB_ENABLED, 0);
            int quer = abrir ? 1 : 0;
            if (agora == quer) return;
            Settings.Global.putInt(c.getContentResolver(), Settings.Global.ADB_ENABLED, quer);
            Registo.escrever(c, abrir ? "manutenção: depuração (adb) aberta" : "manutenção: depuração (adb) fechada");
        } catch (SecurityException e) {
            Registo.escrever(c, "manutenção: sem WRITE_SECURE_SETTINGS");
        }
    }

    private static String ler(File f) throws Exception {
        try (InputStream in = new FileInputStream(f)) {
            return tudo(in);
        }
    }

    private static String tudo(InputStream in) throws Exception {
        ByteArrayOutputStream o = new ByteArrayOutputStream();
        byte[] b = new byte[8192];
        int n;
        while ((n = in.read(b)) > 0) o.write(b, 0, n);
        return new String(o.toByteArray(), StandardCharsets.UTF_8);
    }
}
