<?php

namespace App\Support\Hris;

class TicketStatusMapper
{
  public static function toBoard(string $ticketStatus): string
  {
    return config("managementpro.ticket_status_to_board.{$ticketStatus}", 'todo');
  }

  public static function toTicket(string $boardStatus): string
  {
    return config("managementpro.board_status_to_ticket.{$boardStatus}", 'open');
  }

  public static function priorityToBoard(string $priority): string
  {
    return config("managementpro.priority_to_board.{$priority}", 'medium');
  }

  public static function boardToTicketPriority(string $priority): string
  {
    return config("managementpro.board_priority_to_ticket.{$priority}", 'medium');
  }
}
