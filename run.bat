@echo off
setlocal
cd /d "%~dp0"
if exist ".python\python.exe" (
  .python\python.exe app.py
) else (
  python app.py
)
