package ao.naval.painel;

import android.content.Context;

import java.io.File;
import java.io.FileOutputStream;
import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/**
 * Registo próprio da app (a TCL filtra o registo do Android das apps de fora): files/registo.txt, até ~64 KB (depois
 * recomeça). Lê-se por adb: adb shell run-as ao.naval.painel cat files/registo.txt
 */
final class Registo {
    private Registo() {
    }

    static synchronized void escrever(Context c, String linha) {
        try {
            File f = new File(c.getFilesDir(), "registo.txt");
            boolean recomeca = f.length() > 64 * 1024;
            try (FileOutputStream o = new FileOutputStream(f, !recomeca)) {
                String t = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.ROOT).format(new Date());
                o.write((t + "  " + linha + "\n").getBytes(StandardCharsets.UTF_8));
            }
        } catch (Exception ignorar) {
            // o registo nunca pode parar a app
        }
    }
}
