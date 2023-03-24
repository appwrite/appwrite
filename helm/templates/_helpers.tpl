{{/*
Expand the name of the chart.
*/}}
{{- define "appwrite.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "appwrite.namespace" -}}
{{- default .Release.Namespace .Values.namespaceOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{/*
Create a default fully qualified app name.
We truncate at 63 chars because some Kubernetes name fields are limited to this (by the DNS naming spec).
If release name contains chart name it will be used as a full name.
*/}}
{{- define "appwrite.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Common labels
*/}}
{{- define "appwrite.labels" -}}
app.kubernetes.io/name: {{ include "appwrite.name" . }}
app.kubernetes.io/instance: {{ .Release.Name | quote }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service | quote }}
helm.sh/chart: {{ .Chart.Name }}-{{ .Chart.Version | replace "+" "_" }}
{{- range $name, $value := .Values.commonLabels }}
{{ $name }}: {{ $value  }}
{{- end }}
{{- end -}}

{{/*
Selector labels
*/}}
{{- define "appwrite.selectorLabels" -}}
app.kubernetes.io/name: {{ include "appwrite.name" . }}
app.kubernetes.io/instance: {{ .Release.Name | quote }}
{{- end -}}

{{- define "boolToStr" }}
{{- if . -}}
  "enabled"
{{- else -}}
  "disabled"
{{- end -}}
{{- end -}}

{{- define "_arrayjoin"}}
{{- range $i, $val := . }}
{{- (print $val ",") -}}
{{ end -}}
{{- end -}}

{{- define "array.join" }}
{{- include "_arrayjoin" . | trimSuffix "," | quote -}}
{{- end -}}

{{- define "_sitoNum" }}
{{- if hasSuffix "Gi" . -}}
{{ mul (mul (mul (trimSuffix "Gi" . | atoi) 1024) 1024) 1024 }}
{{- else if hasSuffix "Mi" . -}}
{{ mul (mul (trimSuffix "Mi" . | atoi) 1024) 1024 }}
{{- else if hasSuffix "Ki" . -}}
{{ mul (trimSuffix "Ki" . | atoi) 1024 }}
{{- end -}}
{{- end -}}

{{- define "si.toNum" }}
{{- include "_sitoNum" . | quote -}}
{{- end -}}

{{- define "probeTcp" -}}
livenessProbe:
  tcpSocket:
    port: {{ . }}
  initialDelaySeconds: 5
  periodSeconds: 10
  timeoutSeconds: 3
  failureThreshold: 3
readinessProbe:
  tcpSocket:
    port: {{ . }}
  initialDelaySeconds: 15
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 3
startupProbe:
  tcpSocket:
    port: {{ . }}
  initialDelaySeconds: 60
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 3
{{- end -}}

{{- define "probeHttp" -}}
livenessProbe:
  httpGet:
    path: /health
    port: http
  initialDelaySeconds: 5
  periodSeconds: 10
  timeoutSeconds: 3
  failureThreshold: 3
readinessProbe:
  httpGet:
    path: /ping
    port: http
  initialDelaySeconds: 15
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 3
startupProbe:
  httpGet:
    path: /ping
    port: http
  initialDelaySeconds: 15
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 3
{{- end -}}

# Place it to initContainers section
{{- define "influxdbCheck" -}}

{{- end }}

{{- define "dbCheck" -}}

{{- end }}

{{- define "redisCheck" -}}

{{- end }}
