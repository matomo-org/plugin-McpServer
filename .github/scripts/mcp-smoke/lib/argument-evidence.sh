#!/usr/bin/env bash

mcp_smoke_line_has_exact_argument() {
  local success_line="$1"
  local expected_argument="$2"
  local message_marker="MCP Tool Call successful: "
  local message
  local formatted_arguments
  local arguments_with_suffix

  message=${success_line#*"$message_marker"}
  if [ "$message" = "$success_line" ]; then
    return 1
  fi

  arguments_with_suffix=${message#*" ["}
  if [ "$arguments_with_suffix" = "$message" ]; then
    return 1
  fi

  formatted_arguments=${arguments_with_suffix%"] [session="*}
  if [ "$formatted_arguments" = "$arguments_with_suffix" ]; then
    return 1
  fi

  case ", $formatted_arguments, " in
    *", $expected_argument, "*) return 0 ;;
    *) return 1 ;;
  esac
}
