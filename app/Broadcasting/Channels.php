<?php

declare(strict_types=1);

namespace App\Broadcasting;

/**
 * Los nombres de los canales de tiempo real, en un solo lugar. Todos son privados y todos llevan la empresa en el
 * nombre (RS-01): un canal sin empresa no existe. Quien emite y quien autoriza usan estas mismas funciones, así no
 * pueden desalinearse.
 */
final class Channels
{
    /** La bandeja de una empresa: avisos de que algo cambió en alguno de sus tickets. */
    public static function inbox(int $tenantId): string
    {
        return "tenant.{$tenantId}.inbox";
    }

    /** Un ticket concreto de una empresa: avisos de que cambió su conversación y señales de quien lo está atendiendo. */
    public static function ticket(int $tenantId, int $ticketId): string
    {
        return "tenant.{$tenantId}.tickets.{$ticketId}";
    }

    /** La base de conocimiento de una empresa: avisos de que un documento cambió de estado. */
    public static function knowledge(int $tenantId): string
    {
        return "tenant.{$tenantId}.knowledge";
    }

    /** La conversación de un visitante del widget: solo su propio navegador, con el token de su sesión, puede entrar. */
    public static function widget(int $tenantId, string $sessionId): string
    {
        return "tenant.{$tenantId}.widget.{$sessionId}";
    }
}
