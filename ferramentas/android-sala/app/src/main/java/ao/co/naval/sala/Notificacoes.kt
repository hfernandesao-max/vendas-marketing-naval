package ao.co.naval.sala

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent

/** Notificação do serviço (obrigatória para um serviço em primeiro plano): discreta, sem som. */
object Notificacoes {
    const val ID_SERVICO = 1
    private const val ID_TOQUE = 2
    private const val CANAL = "sala"

    private fun canal(ctx: Context) {
        val nm = ctx.getSystemService(NotificationManager::class.java)
        if (nm.getNotificationChannel(CANAL) == null) {
            nm.createNotificationChannel(NotificationChannel(CANAL, ctx.getString(R.string.canal), NotificationManager.IMPORTANCE_MIN))
        }
    }

    fun servico(ctx: Context, naSala: Boolean): Notification {
        canal(ctx)
        return Notification.Builder(ctx, CANAL)
            .setSmallIcon(android.R.drawable.ic_menu_compass)
            .setContentTitle(ctx.getString(R.string.app_nome))
            .setContentText(ctx.getString(if (naSala) R.string.notif_sala else R.string.notif_vigia))
            .setOngoing(true)
            .build()
    }

    fun estado(ctx: Context, naSala: Boolean) {
        ctx.getSystemService(NotificationManager::class.java).notify(ID_SERVICO, servico(ctx, naSala))
    }

    /** Recurso quando falta a permissão de sobreposição: um toque abre o Winrest (deixa de ser «zero toques»). */
    fun pedirToque(ctx: Context, abrir: Intent) {
        canal(ctx)
        val pi = PendingIntent.getActivity(ctx, 0, abrir, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        ctx.getSystemService(NotificationManager::class.java).notify(
            ID_TOQUE,
            Notification.Builder(ctx, CANAL)
                .setSmallIcon(android.R.drawable.ic_menu_compass)
                .setContentTitle(ctx.getString(R.string.app_nome))
                .setContentText(ctx.getString(R.string.notif_sala))
                .setContentIntent(pi)
                .setAutoCancel(true)
                .build(),
        )
    }
}
